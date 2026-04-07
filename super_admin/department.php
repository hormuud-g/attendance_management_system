<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control - Super Admin only
if (strtolower($_SESSION['user']['role'] ?? '') !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

$message = "";
$type = "";

/* ===========================================
   HANDLE AJAX REQUEST FOR FACULTIES
=========================================== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_faculties') {
    $campus_id = $_GET['campus_id'] ?? null;
    
    if ($campus_id) {
        // Get faculties for specific campus
        $sql = "SELECT f.faculty_id, f.faculty_name, f.faculty_code, f.status 
                FROM faculties f
                JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
                WHERE fc.campus_id = ? AND f.status = 'active'
                ORDER BY f.faculty_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$campus_id]);
        $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Get all active faculties
        $sql = "SELECT faculty_id, faculty_name, faculty_code, status 
                FROM faculties 
                WHERE status = 'active'
                ORDER BY faculty_name ASC";
        $faculties = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $faculties
    ]);
    exit;
}

/* ===========================================
   CRUD OPERATIONS - SUPER ADMIN
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
            
            // ✅ Check if department code already exists
           

            // ✅ Check if email already exists
            if ($email) {
                $emailCheck = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE email = ?");
                $emailCheck->execute([$email]);
                if ($emailCheck->fetchColumn() > 0) {
                    throw new Exception("⚠️ Email already exists!");
                }
            }

            // ✅ Check if head of department already exists
            if ($head_of_department) {
                $headCheck = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE head_of_department = ?");
                $headCheck->execute([$head_of_department]);
                if ($headCheck->fetchColumn() > 0) {
                    throw new Exception("⚠️ Head of Department already assigned!");
                }
            }

            // ✅ Check if phone number already exists
            if ($phone) {
                $phoneCheck = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE phone_number = ?");
                $phoneCheck->execute([$phone]);
                if ($phoneCheck->fetchColumn() > 0) {
                    throw new Exception("⚠️ Phone number already exists!");
                }
            }

            // ✅ Get faculty_id and campus_id
            $faculty_id = $_POST['faculty_id'] ?? null;
            $campus_id = $_POST['campus_id'] ?? null;
            
            if (!$faculty_id || !$campus_id) {
                throw new Exception("Please select both faculty and campus!");
            }

            // ✅ Check if faculty belongs to selected campus
            $facultyCampusCheck = $pdo->prepare("
                SELECT COUNT(*) 
                FROM faculty_campus 
                WHERE faculty_id = ? AND campus_id = ?
            ");
            $facultyCampusCheck->execute([$faculty_id, $campus_id]);

            if ($facultyCampusCheck->fetchColumn() === 0) {
                throw new Exception("⚠️ The selected faculty does not belong to the selected campus!");
            }

            // ✅ Get campus info for office location
            $campus_stmt = $pdo->prepare("SELECT campus_name, address, city, country FROM campus WHERE campus_id = ?");
            $campus_stmt->execute([$campus_id]);
            $campus = $campus_stmt->fetch(PDO::FETCH_ASSOC);
            
            // ✅ Auto-generate office location
            $office_location = trim($_POST['office_location'] ?? '');
            if (empty($office_location) && $campus) {
                $address_parts = [];
                if (!empty($campus['address'])) $address_parts[] = $campus['address'];
                if (!empty($campus['city'])) $address_parts[] = $campus['city'];
                if (!empty($campus['country'])) $address_parts[] = $campus['country'];
                
                $office_location = $address_parts ? implode(', ', $address_parts) : $campus['campus_name'] . " Campus";
            }

            // ✅ Upload profile photo
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

            // ✅ Check if user exists
            $existing_user = null;
            if ($email) {
                $emailUserCheck = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                $emailUserCheck->execute([$email]);
                $existing_user = $emailUserCheck->fetch(PDO::FETCH_ASSOC);
            }

            // ✅ Create or update user account
            if ($existing_user) {
                $updateUser = $pdo->prepare("
                    UPDATE users 
                    SET linked_id = ?, linked_table = 'department' 
                    WHERE email = ?
                ");
                $updateUser->execute([$department_id, $email]);
            } else {
                $uuid = bin2hex(random_bytes(16));
                $plain_pass = "123";
                $hashed = password_hash($plain_pass, PASSWORD_BCRYPT);

                $user = $pdo->prepare("
                    INSERT INTO users 
                    (user_uuid, username, email, phone_number, profile_photo_path, 
                     password, password_plain, role, linked_id, linked_table, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,'active')
                ");
                $user->execute([
                    $uuid,
                    $department_name,
                    $email,
                    $phone,
                    $photo_path,
                    $hashed,
                    $plain_pass,
                    'department_admin',
                    $department_id,
                    'department'
                ]);
            }

            $pdo->commit();
            $message = "✅ Department added successfully! Default password: 123";
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
            
            // ✅ Check if department code already exists (excluding current)
           
            // ✅ Check if email already exists (excluding current)
            if ($email) {
                $emailCheck = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE email = ? AND department_id != ?");
                $emailCheck->execute([$email, $id]);
                if ($emailCheck->fetchColumn() > 0) {
                    throw new Exception("⚠️ Email already exists!");
                }
            }

            // ✅ Check if head of department already exists (excluding current)
            if ($head_of_department) {
                $headCheck = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE head_of_department = ? AND department_id != ?");
                $headCheck->execute([$head_of_department, $id]);
                if ($headCheck->fetchColumn() > 0) {
                    throw new Exception("⚠️ Head of Department already assigned!");
                }
            }

            // ✅ Check if phone number already exists (excluding current)
            if ($phone) {
                $phoneCheck = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE phone_number = ? AND department_id != ?");
                $phoneCheck->execute([$phone, $id]);
                if ($phoneCheck->fetchColumn() > 0) {
                    throw new Exception("⚠️ Phone number already exists!");
                }
            }

            // ✅ Get faculty_id and campus_id
            $faculty_id = $_POST['faculty_id'] ?? null;
            $campus_id = $_POST['campus_id'] ?? null;
            
            if (!$faculty_id || !$campus_id) {
                throw new Exception("Please select both faculty and campus!");
            }

            // ✅ Check if faculty belongs to selected campus
            $facultyCampusCheck = $pdo->prepare("
                SELECT COUNT(*) 
                FROM faculty_campus 
                WHERE faculty_id = ? AND campus_id = ?
            ");
            $facultyCampusCheck->execute([$faculty_id, $campus_id]);

            if ($facultyCampusCheck->fetchColumn() === 0) {
                throw new Exception("⚠️ The selected faculty does not belong to the selected campus!");
            }

            // ✅ Get campus info for office location
            $campus_stmt = $pdo->prepare("SELECT campus_name, address, city, country FROM campus WHERE campus_id = ?");
            $campus_stmt->execute([$campus_id]);
            $campus = $campus_stmt->fetch(PDO::FETCH_ASSOC);
            
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
            $stmt = $pdo->prepare("SELECT profile_photo_path FROM departments WHERE department_id = ?");
            $stmt->execute([$id]);
            $existing_photo = $stmt->fetchColumn();
            $photo_path = $existing_photo;

            // ✅ Handle photo upload
            $setClauses = [
                "department_name = ?",
                "department_code = ?",
                "head_of_department = ?",
                "faculty_id = ?",
                "campus_id = ?",
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
                $campus_id,
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

            // ✅ Update department
            $sql = "UPDATE departments SET " . implode(', ', $setClauses) . " WHERE department_id = ?";
            $pdo->prepare($sql)->execute($params);

            // ✅ Sync user account
            $userStatus = ($_POST['status'] === 'inactive') ? 'inactive' : 'active';
            $plain = "123";
            $hashed = password_hash($plain, PASSWORD_BCRYPT);

            $updateUser = $pdo->prepare("UPDATE users 
                SET username=?, email=?, phone_number=?, password=?, password_plain=?, profile_photo_path=?, status=? 
                WHERE linked_id=? AND linked_table='department'");
            $updateUser->execute([
                $department_name,
                $email,
                $phone,
                $hashed,
                $plain,
                $photo_path ?? null,
                $userStatus,
                $id
            ]);

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

            $pdo->prepare("DELETE FROM users WHERE linked_id=? AND linked_table='department'")->execute([$id]);
            $pdo->prepare("DELETE FROM departments WHERE department_id=?")->execute([$id]);
            
            $pdo->commit();
            $message = "✅ Department deleted successfully!";
            $type = "success";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }
}

// ✅ GET FILTER PARAMETERS
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$faculty_filter = $_GET['faculty'] ?? '';
$campus_filter = $_GET['campus'] ?? '';

// ✅ Fetch all campuses for filter dropdown
$campuses = $pdo->query("SELECT campus_id, campus_name, campus_code, address, city, country, status FROM campus ORDER BY campus_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// ✅ Fetch all faculties for filter dropdown (with campus info)
$faculties = $pdo->query("
    SELECT 
        f.faculty_id, 
        f.faculty_name, 
        f.faculty_code, 
        f.status,
        GROUP_CONCAT(c.campus_name SEPARATOR ', ') as campus_names,
        GROUP_CONCAT(fc.campus_id SEPARATOR ',') as campus_ids
    FROM faculties f
    LEFT JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
    LEFT JOIN campus c ON fc.campus_id = c.campus_id
    WHERE f.status = 'active'
    GROUP BY f.faculty_id
    ORDER BY f.faculty_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ✅ Build query with filters
$query = "
    SELECT 
        d.*,
        f.faculty_name,
        f.faculty_code as faculty_code,
        c.campus_name,
        c.campus_code as campus_code,
        c.city as campus_city,
        c.country as campus_country,
        CONCAT(c.campus_name, ' (', c.campus_code, ')') as campus_full_name
    FROM departments d
    JOIN faculties f ON d.faculty_id = f.faculty_id
    JOIN campus c ON d.campus_id = c.campus_id
    WHERE 1=1
    AND f.status = 'active'
    AND c.status = 'active'
";

$params = [];

// Search filter
if (!empty($search)) {
    $query .= " AND (d.department_name LIKE ? OR d.department_code LIKE ? OR d.head_of_department LIKE ? OR d.email LIKE ? OR d.phone_number LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
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

// Campus filter
if (!empty($campus_filter)) {
    $query .= " AND d.campus_id = ?";
    $params[] = $campus_filter;
}

$query .= " ORDER BY d.department_id DESC";

// ✅ Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Get total counts for stats
$total_departments = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
$active_departments = $pdo->query("SELECT COUNT(*) FROM departments WHERE status='active'")->fetchColumn();
$inactive_departments = $pdo->query("SELECT COUNT(*) FROM departments WHERE status='inactive'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Department Management | University System</title>
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="../assets/css/header.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
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

.page-header h1 {
  color: var(--secondary-color);
  font-size: 26px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 12px;
}

.page-header h1 i {
  color: var(--primary-color);
  background: rgba(0, 132, 61, 0.1);
  padding: 10px;
  border-radius: 10px;
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
  max-width: 900px;
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

/* Faculty and Campus Selection */
.selection-group {
  grid-column: 1 / -1;
  margin: 20px 0;
  padding: 20px;
  background: #f8f9fa;
  border-radius: 10px;
  border: 2px solid #e0e0e0;
}

.selection-group label {
  font-weight: 600;
  color: var(--secondary-color);
  margin-bottom: 15px;
  display: block;
  font-size: 16px;
}

.select2-container {
  width: 100% !important;
}

.select2-selection {
  border: 2px solid #e0e0e0 !important;
  border-radius: 8px !important;
  min-height: 48px !important;
  padding: 8px !important;
}

.select2-selection:focus {
  border-color: var(--secondary-color) !important;
  outline: none !important;
  box-shadow: 0 0 0 4px rgba(0, 114, 206, 0.1) !important;
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
.modal-content::-webkit-scrollbar,
.select2-results__options::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

.table-container::-webkit-scrollbar-track,
.modal-content::-webkit-scrollbar-track,
.select2-results__options::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 4px;
}

.table-container::-webkit-scrollbar-thumb,
.modal-content::-webkit-scrollbar-thumb,
.select2-results__options::-webkit-scrollbar-thumb {
  background: var(--secondary-color);
  border-radius: 4px;
}

.table-container::-webkit-scrollbar-thumb:hover,
.modal-content::-webkit-scrollbar-thumb:hover,
.select2-results__options::-webkit-scrollbar-thumb:hover {
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
  <div class="page-header">
    <h1><i class="fas fa-building"></i> Department Management</h1>
    <button class="add-btn" onclick="openModal('addModal')">
      <i class="fa-solid fa-plus"></i> Add New Department
    </button>
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
                 placeholder="Department, code, head..."
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
        <label for="faculty">Faculty</label>
        <div style="position:relative;">
          <i class="fas fa-university filter-icon"></i>
          <select id="faculty" name="faculty" class="filter-input">
            <option value="">All Faculties</option>
            <?php foreach($faculties as $faculty): 
              $campusNames = isset($faculty['campus_names']) ? ' - ' . $faculty['campus_names'] : '';
              $disabled = $faculty['status'] === 'inactive' ? 'disabled' : '';
              $selected = $faculty_filter == $faculty['faculty_id'] ? 'selected' : '';
            ?>
              <option value="<?= $faculty['faculty_id'] ?>" 
                <?= $disabled ?> <?= $selected ?>
                data-campuses="<?= htmlspecialchars($faculty['campus_ids'] ?? '') ?>">
                <?= htmlspecialchars($faculty['faculty_name']) ?> 
                (<?= htmlspecialchars($faculty['faculty_code']) ?>)
                <?= $campusNames ?>
                <?= $faculty['status'] === 'inactive' ? ' - Inactive' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      
      <div class="filter-group">
        <label for="campus">Campus</label>
        <div style="position:relative;">
          <i class="fas fa-map-marker-alt filter-icon"></i>
          <select id="campus" name="campus" class="filter-input">
            <option value="">All Campuses</option>
            <?php foreach($campuses as $campus): ?>
              <option value="<?= $campus['campus_id'] ?>" 
                <?= $campus_filter == $campus['campus_id'] ? 'selected' : '' ?>
                <?= $campus['status'] === 'inactive' ? 'disabled' : '' ?>>
                <?= htmlspecialchars($campus['campus_name']) ?> (<?= htmlspecialchars($campus['campus_code']) ?>)
                <?= $campus['status'] === 'inactive' ? ' - Inactive' : '' ?>
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
        Showing <?= count($departments) ?> of <?= $total_departments ?> departments
        <?php if(!empty($search) || !empty($status_filter) || !empty($faculty_filter) || !empty($campus_filter)): ?>
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
            <th>Head</th>
            <th>Faculty</th>
            <th>Campus & Office Location</th>
            <th>Phone</th>
            <th>Email</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if($departments): ?>
            <?php foreach($departments as $i=>$d): 
              // ✅ Generate initials for profile photo
              $initials = '';
              $department_name = htmlspecialchars($d['department_name']);
              $name_parts = explode(' ', $department_name);
              if(count($name_parts) >= 2) {
                $initials = strtoupper(substr($name_parts[0], 0, 1) . substr($name_parts[1], 0, 1));
              } else {
                $initials = strtoupper(substr($department_name, 0, 2));
              }
              if(strlen($initials) === 1) {
                $initials = $initials . strtoupper(substr($department_name, 1, 1));
              }
            ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td>
                <div class="profile-photo-container">
                  <?php if(!empty($d['profile_photo_path'])): ?>
                    <div class="profile-photo" onclick="openViewModal(<?= $d['department_id'] ?>)">
                      <img src="../<?= $d['profile_photo_path'] ?>" 
                           alt="<?= $department_name ?>"
                           onerror="this.parentElement.innerHTML='<div class=\"profile-initials\"><?= $initials ?></div>
                    </div>
                  <?php else: ?>
                    <div class="profile-photo" onclick="openViewModal(<?= $d['department_id'] ?>)">
                      <div class="profile-initials"><?= $initials ?></div>
                    </div>
                  <?php endif; ?>
                </div>
              </td>
              <td><strong><?= $department_name ?></strong></td>
              <td><span class="code-badge"><?= htmlspecialchars($d['department_code']) ?></span></td>
              <td><?= htmlspecialchars($d['head_of_department']) ?></td>
              <td>
                <div class="faculty-badge">
                  <i class="fas fa-university"></i>
                  <?= htmlspecialchars($d['faculty_name']) ?>
                  <div class="faculty-badge-code"><?= htmlspecialchars($d['faculty_code']) ?></div>
                </div>
              </td>
              <td>
                <div><strong><?= htmlspecialchars($d['campus_name']) ?></strong></div>
                <div class="location-info">
                  <i class="fas fa-map-marker-alt"></i>
                  <?= htmlspecialchars($d['office_location']) ?>
                </div>
                <?php if($d['campus_city'] || $d['campus_country']): ?>
                <div class="location-info">
                  <i class="fas fa-city"></i>
                  <?= htmlspecialchars($d['campus_city'] ?? '') ?>
                  <?= $d['campus_city'] && $d['campus_country'] ? ', ' : '' ?>
                  <?= htmlspecialchars($d['campus_country'] ?? '') ?>
                </div>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($d['phone_number']) ?></td>
              <td><?= htmlspecialchars($d['email']) ?></td>
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
                            '<?= addslashes($d['head_of_department']) ?>',
                            '<?= addslashes($d['phone_number']) ?>',
                            '<?= addslashes($d['email']) ?>',
                            '<?= addslashes($d['office_location']) ?>',
                            '<?= $d['status'] ?>',
                            <?= $d['faculty_id'] ?>,
                            <?= $d['campus_id'] ?>)"
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
                    <?php if(!empty($search) || !empty($status_filter) || !empty($faculty_filter) || !empty($campus_filter)): ?>
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
            <div class="location-card-location">
              <i class="fas fa-university"></i>
              Faculty
            </div>
          </div>
          
          <div class="location-card">
            <div class="location-card-header">
              <div class="location-card-name" id="view_campus_name"></div>
              <div class="location-card-code" id="view_campus_code"></div>
            </div>
            <div class="location-card-location">
              <i class="fas fa-map-marker-alt"></i>
              <span id="view_campus_location"></span>
            </div>
          </div>
          
          <div class="location-card" style="grid-column: 1 / -1;">
            <div class="location-card-header">
              <div class="location-card-name"><i class="fas fa-location-dot"></i> Office Location</div>
            </div>
            <div class="location-card-location">
              <i class="fas fa-building"></i>
              <span id="view_office_location"></span>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <div class="view-actions" style="grid-column: 1 / -1; display: flex; justify-content: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
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
      
      <div class="form-grid">
        <div class="form-group">
          <label for="department_name">Department Name *</label>
          <input type="text" id="department_name" name="department_name" class="form-control" required>
        </div>
        
        <div class="form-group">
          <label for="department_code">Department Code *</label>
          <input type="text" id="department_code" name="department_code" class="form-control" required>
          <small style="color: #666; margin-top: 5px; display: block;">Unique department code (e.g., CS, ENG, BIO)</small>
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
      
      <!-- Campus Selection (First - because faculties depend on campus) -->
      <div class="selection-group">
        <label for="campus_id">Select Campus *</label>
        <select id="campus_id" name="campus_id" class="form-control" required onchange="updateOfficeLocation(this.value); loadFacultiesByCampus(this.value, 'faculty_id');">
          <option value="">Select Campus</option>
          <?php foreach($campuses as $campus): ?>
            <?php if($campus['status'] === 'active'): ?>
              <option value="<?= $campus['campus_id'] ?>">
                <?= htmlspecialchars($campus['campus_name']) ?> (<?= htmlspecialchars($campus['campus_code']) ?>)
              </option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>
      
      <!-- Faculty Selection (Will be dynamically loaded based on campus) -->
      <div class="selection-group">
        <label for="faculty_id">Select Faculty *</label>
        <select id="faculty_id" name="faculty_id" class="form-control" required disabled>
          <option value="">First select a campus</option>
        </select>
        <div id="facultyWarning" style="color: #666; margin-top: 10px; font-size: 14px; display: none;">
          <i class="fas fa-info-circle"></i> Select a campus first to see available faculties
        </div>
      </div>
      
      <!-- Office Location -->
      <div class="office-location-display" id="officeLocationDisplay" style="display: none;">
        <h4><i class="fas fa-map-marker-alt"></i> Office Location (Auto-generated)</h4>
        <div class="form-group" style="margin-bottom: 0;">
          <input type="text" id="office_location" name="office_location" class="form-control" required>
          <small style="display: block; margin-top: 5px; color: #666;">
            Office location will auto-update when you select a campus. You can also edit it manually.
          </small>
        </div>
        <div id="campusAddressPreview" style="margin-top: 10px; font-size: 12px; color: #666;"></div>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-save"></i> Save Department
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
      
      <div class="form-grid">
        <div class="form-group">
          <label for="edit_name">Department Name *</label>
          <input type="text" id="edit_name" name="department_name" class="form-control" required>
        </div>
        
        <div class="form-group">
          <label for="edit_code">Department Code *</label>
          <input type="text" id="edit_code" name="department_code" class="form-control" required>
          <small style="color: #666; margin-top: 5px; display: block;">Unique department code</small>
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
          <small style="color: #666;">Leave empty to keep current</small>
        </div>
      </div>
      
      <!-- Campus Selection for Edit -->
      <div class="selection-group">
        <label for="edit_campus">Campus *</label>
        <select id="edit_campus" name="campus_id" class="form-control" required onchange="updateEditOfficeLocation(this.value); loadFacultiesByCampus(this.value, 'edit_faculty', document.getElementById('edit_faculty').value);">
          <option value="">Select Campus</option>
          <?php foreach($campuses as $campus): ?>
            <?php if($campus['status'] === 'active'): ?>
              <option value="<?= $campus['campus_id'] ?>">
                <?= htmlspecialchars($campus['campus_name']) ?> (<?= htmlspecialchars($campus['campus_code']) ?>)
              </option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>
      
      <!-- Faculty Selection for Edit (Will be dynamically loaded based on campus) -->
      <div class="selection-group">
        <label for="edit_faculty">Faculty *</label>
        <select id="edit_faculty" name="faculty_id" class="form-control" required>
          <option value="">Select Faculty</option>
        </select>
      </div>
      
      <!-- Office Location for Edit -->
      <div class="office-location-display" id="editOfficeLocationDisplay">
        <h4><i class="fas fa-map-marker-alt"></i> Office Location</h4>
        <div class="form-group" style="margin-bottom: 0;">
          <input type="text" id="edit_office" name="office_location" class="form-control" required>
          <small style="display: block; margin-top: 5px; color: #666;">
            Office location will auto-update when you change the campus. You can also edit it manually.
          </small>
        </div>
        <div id="editCampusAddressPreview" style="margin-top: 10px; font-size: 12px; color: #666;"></div>
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

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script>
// Campus data for JavaScript
const campuses = {
    <?php foreach($campuses as $campus): ?>
    <?= $campus['campus_id'] ?>: {
        name: '<?= addslashes($campus['campus_name']) ?>',
        code: '<?= addslashes($campus['campus_code']) ?>',
        address: '<?= addslashes($campus['address'] ?? '') ?>',
        city: '<?= addslashes($campus['city'] ?? '') ?>',
        country: '<?= addslashes($campus['country'] ?? '') ?>',
        status: '<?= $campus['status'] ?>'
    },
    <?php endforeach; ?>
};

// Department data for view modal
const departmentData = {
    <?php foreach($departments as $d): ?>
    <?= $d['department_id'] ?>: {
        name: '<?= addslashes($d['department_name']) ?>',
        code: '<?= addslashes($d['department_code']) ?>',
        head: '<?= addslashes($d['head_of_department']) ?>',
        phone: '<?= addslashes($d['phone_number']) ?>',
        email: '<?= addslashes($d['email']) ?>',
        office: '<?= addslashes($d['office_location']) ?>',
        status: '<?= $d['status'] ?>',
        faculty_id: <?= $d['faculty_id'] ?>,
        faculty_name: '<?= addslashes($d['faculty_name']) ?>',
        faculty_code: '<?= addslashes($d['faculty_code']) ?>',
        campus_id: <?= $d['campus_id'] ?>,
        campus_name: '<?= addslashes($d['campus_name']) ?>',
        campus_code: '<?= addslashes($d['campus_code']) ?>',
        campus_city: '<?= addslashes($d['campus_city'] ?? '') ?>',
        campus_country: '<?= addslashes($d['campus_country'] ?? '') ?>',
        photo: '<?= $d['profile_photo_path'] ?? '' ?>',
        initials: '<?php 
            $name = htmlspecialchars($d['department_name']);
            $parts = explode(' ', $name);
            if(count($parts) >= 2) {
                echo strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
            } else {
                echo strtoupper(substr($name, 0, 2));
            }
        ?>'
    },
    <?php endforeach; ?>
};

// Initialize Select2
$(document).ready(function() {
    $('#faculty, #campus, #status').select2({
        placeholder: "Select option",
        allowClear: true,
        width: '100%'
    });
    
    $('#campus_id, #edit_campus').select2({
        placeholder: "Select option",
        width: '100%'
    });
    
    $('#faculty_id, #edit_faculty').select2({
        placeholder: "Select option",
        width: '100%'
    });
});

// Modal functions
function openModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        // Reset form if opening add modal
        if (id === 'addModal') {
            document.getElementById('addForm').reset();
            document.getElementById('faculty_id').disabled = true;
            document.getElementById('facultyWarning').style.display = 'block';
            document.getElementById('officeLocationDisplay').style.display = 'none';
            $('#faculty_id').val('').trigger('change');
            $('#campus_id').val('').trigger('change');
        }
    }
}

function closeModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
    }
}

// Clear all filters
function clearFilters() {
    window.location.href = window.location.pathname;
}

// Function to load faculties based on selected campus
function loadFacultiesByCampus(campusId, targetSelectId, selectedFacultyId = null) {
    const targetSelect = document.getElementById(targetSelectId);
    const facultyWarning = document.getElementById('facultyWarning');
    
    if (!campusId) {
        // If no campus selected, disable faculty selection
        targetSelect.disabled = true;
        targetSelect.innerHTML = '<option value="">First select a campus</option>';
        if (facultyWarning) {
            facultyWarning.style.display = 'block';
        }
        $(`#${targetSelectId}`).select2();
        return;
    }
    
    // Enable faculty selection
    targetSelect.disabled = false;
    if (facultyWarning) {
        facultyWarning.style.display = 'none';
    }
    
    // Show loading
    targetSelect.innerHTML = '<option value="">Loading faculties...</option>';
    $(`#${targetSelectId}`).select2();
    
    // Get faculties for specific campus using AJAX to current page
    $.ajax({
        url: window.location.pathname + '?ajax=get_faculties&campus_id=' + campusId,
        type: 'GET',
        success: function(response) {
            if (response.success) {
                const faculties = response.data;
                let options = '<option value="">Select Faculty</option>';
                
                if (faculties.length === 0) {
                    options += '<option value="" disabled>No faculties available for this campus</option>';
                    showWarning('No faculties are available for the selected campus. Please select a different campus.');
                } else {
                    faculties.forEach(faculty => {
                        const selected = (selectedFacultyId && faculty.faculty_id == selectedFacultyId) ? 'selected' : '';
                        const disabled = faculty.status === 'inactive' ? 'disabled' : '';
                        const inactiveLabel = faculty.status === 'inactive' ? ' - Inactive' : '';
                        
                        options += `<option value="${faculty.faculty_id}" ${selected} ${disabled}>
                            ${faculty.faculty_name} (${faculty.faculty_code})${inactiveLabel}
                        </option>`;
                    });
                }
                
                targetSelect.innerHTML = options;
                
                // Re-initialize Select2
                $(`#${targetSelectId}`).select2();
            }
        },
        error: function() {
            targetSelect.innerHTML = '<option value="">Error loading faculties</option>';
            $(`#${targetSelectId}`).select2();
        }
    });
}

// Auto-update office location based on campus selection
function updateOfficeLocation(campusId) {
    const officeLocationDisplay = document.getElementById('officeLocationDisplay');
    const officeLocationInput = document.getElementById('office_location');
    const campusAddressPreview = document.getElementById('campusAddressPreview');
    
    if (campusId && campuses[campusId]) {
        const campus = campuses[campusId];
        
        // Show office location section
        officeLocationDisplay.style.display = 'block';
        
        // Auto-generate office location
        const addressParts = [];
        if (campus.address) addressParts.push(campus.address);
        if (campus.city) addressParts.push(campus.city);
        if (campus.country) addressParts.push(campus.country);
        
        const officeLocation = addressParts.length > 0 ? 
            addressParts.join(', ') : 
            campus.name + " Campus";
        
        // Set office location input
        officeLocationInput.value = officeLocation;
        
        // Show campus address preview
        campusAddressPreview.innerHTML = `
            <i class="fas fa-university"></i> ${campus.name} (${campus.code})<br>
            ${campus.address ? `<i class="fas fa-road"></i> ${campus.address}<br>` : ''}
            ${campus.city ? `<i class="fas fa-city"></i> ${campus.city}` : ''}
            ${campus.country ? `, ${campus.country}` : ''}
        `;
    } else {
        officeLocationDisplay.style.display = 'none';
        campusAddressPreview.innerHTML = '';
    }
}

function updateEditOfficeLocation(campusId) {
    const officeLocationInput = document.getElementById('edit_office');
    const campusAddressPreview = document.getElementById('editCampusAddressPreview');
    
    if (campusId && campuses[campusId]) {
        const campus = campuses[campusId];
        
        // Auto-generate office location
        const addressParts = [];
        if (campus.address) addressParts.push(campus.address);
        if (campus.city) addressParts.push(campus.city);
        if (campus.country) addressParts.push(campus.country);
        
        const officeLocation = addressParts.length > 0 ? 
            addressParts.join(', ') : 
            campus.name + " Campus";
        
        // Set office location input
        officeLocationInput.value = officeLocation;
        
        // Show campus address preview
        campusAddressPreview.innerHTML = `
            <i class="fas fa-university"></i> ${campus.name} (${campus.code})<br>
            ${campus.address ? `<i class="fas fa-road"></i> ${campus.address}<br>` : ''}
            ${campus.city ? `<i class="fas fa-city"></i> ${campus.city}` : ''}
            ${campus.country ? `, ${campus.country}` : ''}
        `;
    } else {
        campusAddressPreview.innerHTML = '';
    }
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
    document.getElementById('view_office_location').textContent = dept.office || 'Not provided';
    
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
    
    const campusLocation = [];
    if (dept.campus_city) campusLocation.push(dept.campus_city);
    if (dept.campus_country) campusLocation.push(dept.campus_country);
    document.getElementById('view_campus_location').textContent = campusLocation.join(', ') || 'Not specified';
    
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
function openEditModal(id, name, code, head, phone, email, office, status, facultyId, campusId) {
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
    
    // Set campus and load faculties
    document.getElementById('edit_campus').value = campusId;
    $('#edit_campus').trigger('change');
    
    // Update office location preview
    if (campusId) {
        updateEditOfficeLocation(campusId);
        // Load faculties for this campus with the current faculty pre-selected
        setTimeout(() => {
            loadFacultiesByCampus(campusId, 'edit_faculty', facultyId);
        }, 300);
    }
}

// ✅ OPEN DELETE MODAL
function openDeleteModal(id) {
    openModal('deleteModal');
    document.getElementById('delete_id').value = id;
}

// Form validation
function validateAddForm() {
    const campusId = document.getElementById('campus_id').value;
    const facultyId = document.getElementById('faculty_id').value;
    
    if (!campusId || !facultyId) {
        alert('Please select both campus and faculty!');
        return false;
    }
    
    // Check if faculty is disabled (inactive)
    const facultySelect = document.getElementById('faculty_id');
    const selectedOption = facultySelect.options[facultySelect.selectedIndex];
    if (selectedOption.disabled) {
        alert('Cannot select an inactive faculty!');
        return false;
    }
    
    return true;
}

function validateEditForm() {
    const campusId = document.getElementById('edit_campus').value;
    const facultyId = document.getElementById('edit_faculty').value;
    
    if (!campusId || !facultyId) {
        alert('Please select both campus and faculty!');
        return false;
    }
    
    // Check if faculty is disabled (inactive)
    const facultySelect = document.getElementById('edit_faculty');
    const selectedOption = facultySelect.options[facultySelect.selectedIndex];
    if (selectedOption.disabled) {
        alert('Cannot select an inactive faculty!');
        return false;
    }
    
    return true;
}

// Show warning message
function showWarning(message) {
    // Create or use existing warning element
    let warningEl = document.getElementById('campusWarning');
    if (!warningEl) {
        warningEl = document.createElement('div');
        warningEl.id = 'campusWarning';
        warningEl.style.cssText = `
            padding: 10px 15px;
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            color: #856404;
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        `;
        const campusSelect = document.getElementById('campus_id') || document.getElementById('edit_campus');
        campusSelect.parentNode.insertBefore(warningEl, campusSelect.nextSibling);
    }
    
    warningEl.innerHTML = `
        <i class="fas fa-exclamation-triangle"></i>
        <span>${message}</span>
    `;
    warningEl.style.display = 'block';
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        warningEl.style.display = 'none';
    }, 5000);
}

// Auto-hide alert
document.addEventListener('DOMContentLoaded', function() {
    const alert = document.getElementById('popup');
    if (alert && alert.classList.contains('show')) {
        setTimeout(() => {
            alert.classList.remove('show');
        }, 3500);
    }
    
    // Auto-submit filters on change
    document.getElementById('status').addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    $('#faculty, #campus').on('change', function() {
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