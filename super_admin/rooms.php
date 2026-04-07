<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Check login
if (empty($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

// ✅ Access control - Super Admin only
if (strtolower($_SESSION['user']['role'] ?? '') !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

$user  = $_SESSION['user'];
$role  = strtolower(str_replace(' ', '_', $user['role'] ?? ''));
$name  = htmlspecialchars($user['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars($user['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$phone = htmlspecialchars($user['phone_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$photo = !empty($user['profile_photo_path'])
    ? "../" . htmlspecialchars($user['profile_photo_path'], ENT_QUOTES, 'UTF-8')
    : "../upload/profiles/default.png";

$message = "";
$type = "";

/* =========================================================
   CRUD OPERATIONS
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 🟢 ADD ROOM
    if ($action === 'add') {
        try {
            $campus_id = $_POST['campus_id'] ?? null;
            $faculty_id = $_POST['faculty_id'] ?? null;
            $department_id = $_POST['department_id'] ?? null;
            $room_code = trim($_POST['room_code'] ?? '');
            
            // ✅ Validate required fields
            if (empty($campus_id) || empty($faculty_id)) {
                $message = "⚠️ Please select campus and faculty!";
                $type = "error";
            } else {
                // ✅ Check same room_code *within the same campus only*
                $check = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_code=? AND campus_id=?");
                $check->execute([$room_code, $campus_id]);

                if ($check->fetchColumn() > 0) {
                    $message = "⚠️ Room code already exists in this campus!";
                    $type = "error";
                } else {
                    // Validate faculty belongs to selected campus
                    $checkFaculty = $pdo->prepare("SELECT COUNT(*) FROM faculty_campus WHERE faculty_id=? AND campus_id=?");
                    $checkFaculty->execute([$faculty_id, $campus_id]);
                    
                    if ($checkFaculty->fetchColumn() == 0) {
                        $message = "⚠️ Selected faculty does not belong to the selected campus!";
                        $type = "error";
                    } else {
                        // Validate department belongs to selected faculty and campus
                        if (!empty($department_id)) {
                            $checkDept = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_id=? AND faculty_id=? AND campus_id=?");
                            $checkDept->execute([$department_id, $faculty_id, $campus_id]);
                            
                            if ($checkDept->fetchColumn() == 0) {
                                $message = "⚠️ Selected department does not belong to the selected faculty and campus!";
                                $type = "error";
                            }
                        }
                        
                        // All validations passed, insert room
                        $stmt = $pdo->prepare("INSERT INTO rooms 
                            (campus_id, faculty_id, department_id, building_name, floor_no, room_name, room_code, capacity, room_type, description, status)
                            VALUES (?,?,?,?,?,?,?,?,?,?, 'available')");
                        $stmt->execute([
                            $campus_id, $faculty_id, $department_id,
                            $_POST['building_name'], $_POST['floor_no'], $_POST['room_name'],
                            $room_code, $_POST['capacity'], $_POST['room_type'],
                            $_POST['description']
                        ]);
                        $message = "✅ Room added successfully!";
                        $type = "success";
                    }
                }
            }

        } catch (PDOException $e) {
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }
    
    // 🟡 UPDATE ROOM
    if ($action === 'update') {
        try {
            $room_id   = $_POST['room_id'] ?? null;
            $campus_id = $_POST['campus_id'] ?? null;
            $faculty_id = $_POST['faculty_id'] ?? null;
            $department_id = $_POST['department_id'] ?? null;
            $room_code = trim($_POST['room_code'] ?? '');
            
            // ✅ Validate required fields
            if (empty($campus_id) || empty($faculty_id)) {
                $message = "⚠️ Please select campus and faculty!";
                $type = "error";
            } else {
                // Validate faculty belongs to selected campus
                $checkFaculty = $pdo->prepare("SELECT COUNT(*) FROM faculty_campus WHERE faculty_id=? AND campus_id=?");
                $checkFaculty->execute([$faculty_id, $campus_id]);
                
                if ($checkFaculty->fetchColumn() == 0) {
                    $message = "⚠️ Selected faculty does not belong to the selected campus!";
                    $type = "error";
                } else {
                    // Validate department belongs to selected faculty and campus
                    if (!empty($department_id)) {
                        $checkDept = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE department_id=? AND faculty_id=? AND campus_id=?");
                        $checkDept->execute([$department_id, $faculty_id, $campus_id]);
                        
                        if ($checkDept->fetchColumn() == 0) {
                            $message = "⚠️ Selected department does not belong to the selected faculty and campus!";
                            $type = "error";
                        }
                    }
                    
                    // ✅ Check duplication only if same code exists in same campus but different room
                    $check = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_code=? AND campus_id=? AND room_id<>?");
                    $check->execute([$room_code, $campus_id, $room_id]);

                    if ($check->fetchColumn() > 0) {
                        $message = "⚠️ Room code already exists in this campus!";
                        $type = "error";
                    } else {
                        $stmt = $pdo->prepare("UPDATE rooms 
                            SET campus_id=?, faculty_id=?, department_id=?, building_name=?, floor_no=?, room_name=?, room_code=?, capacity=?, room_type=?, description=?, status=? 
                            WHERE room_id=?");
                        $stmt->execute([
                            $campus_id, $faculty_id, $department_id,
                            $_POST['building_name'], $_POST['floor_no'], $_POST['room_name'],
                            $room_code, $_POST['capacity'], $_POST['room_type'],
                            $_POST['description'], $_POST['status'], $room_id
                        ]);
                        $message = "✅ Room updated successfully!";
                        $type = "success";
                    }
                }
            }

        } catch (PDOException $e) {
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }

    // 🔴 DELETE ROOM
    if ($action === 'delete') {
        try {
            $pdo->prepare("DELETE FROM rooms WHERE room_id=?")->execute([$_POST['room_id']]);
            $message = "✅ Room deleted successfully!";
            $type = "success";
        } catch (PDOException $e) {
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }
}

/* =========================================================
   FETCH FILTER PARAMETERS
========================================================= */
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? '';
$campus_filter = $_GET['campus'] ?? '';
$faculty_filter = $_GET['faculty'] ?? '';
$type_filter = $_GET['type'] ?? '';

/* =========================================================
   FETCH RELATED DATA
========================================================= */
$campuses = $pdo->query("SELECT campus_id, campus_name, campus_code FROM campus ORDER BY campus_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$faculties = $pdo->query("SELECT faculty_id, faculty_name, faculty_code FROM faculties ORDER BY faculty_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$facultyCampus = $pdo->query("SELECT faculty_id, campus_id FROM faculty_campus")->fetchAll(PDO::FETCH_ASSOC);
$facultyCampusMap = [];
foreach ($facultyCampus as $fc) {
    $facultyCampusMap[$fc['faculty_id']][] = $fc['campus_id'];
}

$departments = $pdo->query("SELECT department_id, department_name, department_code, faculty_id, campus_id FROM departments ORDER BY department_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// ✅ Build query with filters
$query = "SELECT r.*, 
            c.campus_name, c.campus_code as campus_code,
            f.faculty_name, f.faculty_code as faculty_code,
            d.department_name, d.department_code as department_code
          FROM rooms r
          LEFT JOIN campus c ON r.campus_id = c.campus_id
          LEFT JOIN faculties f ON r.faculty_id = f.faculty_id
          LEFT JOIN departments d ON r.department_id = d.department_id
          WHERE 1=1";

$params = [];

// Search filter
if (!empty($search)) {
    $query .= " AND (r.room_name LIKE ? OR r.room_code LIKE ? OR r.building_name LIKE ? OR r.description LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

// Status filter
if (!empty($status_filter)) {
    $query .= " AND r.status = ?";
    $params[] = $status_filter;
}

// Campus filter
if (!empty($campus_filter)) {
    $query .= " AND r.campus_id = ?";
    $params[] = $campus_filter;
}

// Faculty filter
if (!empty($faculty_filter)) {
    $query .= " AND r.faculty_id = ?";
    $params[] = $faculty_filter;
}

// Type filter
if (!empty($type_filter)) {
    $query .= " AND r.room_type = ?";
    $params[] = $type_filter;
}

$query .= " ORDER BY r.room_id DESC";

// ✅ Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ Get total counts for stats
$total_rooms = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
$available_rooms = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='available'")->fetchColumn();
$occupied_rooms = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='occupied'")->fetchColumn();
$maintenance_rooms = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='maintenance'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Room Management | University System</title>
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<style>

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
.stat-card.available { border-left-color: var(--primary-color); }
.stat-card.occupied { border-left-color: var(--purple-color); }
.stat-card.maintenance { border-left-color: var(--warning-color); }

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
.stat-icon.available { background: var(--primary-color); }
.stat-icon.occupied { background: var(--purple-color); }
.stat-icon.maintenance { background: var(--warning-color); }

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
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 15px;
  align-items: flex-end;
}

.filter-group {
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

.data-table tbody tr:last-child td {
  border-bottom: none;
}

/* Room code badge */
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

/* Location info */
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

.status-available {
  background: #e8f5e8;
  color: var(--primary-color);
  border: 1px solid rgba(0, 132, 61, 0.2);
}

.status-occupied {
  background: #f3e5f5;
  color: var(--purple-color);
  border: 1px solid rgba(106, 90, 205, 0.2);
}

.status-maintenance {
  background: #fff3e0;
  color: var(--warning-color);
  border: 1px solid rgba(255, 180, 0, 0.2);
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
  grid-template-columns: 1fr;
  gap: 25px;
}

.view-details {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
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

/* Location Section in View Modal */
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

/* Selection groups */
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
   VALIDATION STYLES
   ============================== */
.error-message {
  color: var(--danger-color);
  font-size: 12px;
  margin-top: 5px;
  display: none;
}

.form-control.error {
  border-color: var(--danger-color);
}

.form-control.valid {
  border-color: var(--primary-color);
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
  
  .filter-form {
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  }
  
  .view-details {
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
    grid-template-columns: 1fr;
  }
  
  .filter-actions {
    flex-direction: column;
    align-items: stretch;
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

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1><i class="fas fa-door-closed"></i> Room Management</h1>
    <button class="add-btn" onclick="openModal('addModal')">
      <i class="fa-solid fa-plus"></i> Add New Room
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
                 placeholder="Room name, code, building..."
                 value="<?= htmlspecialchars($search) ?>">
        </div>
      </div>
      
      <div class="filter-group">
        <label for="status">Status</label>
        <div style="position:relative;">
          <i class="fas fa-circle filter-icon"></i>
          <select id="status" name="status" class="filter-input">
            <option value="">All Status</option>
            <option value="available" <?= $status_filter === 'available' ? 'selected' : '' ?>>Available</option>
            <option value="occupied" <?= $status_filter === 'occupied' ? 'selected' : '' ?>>Occupied</option>
            <option value="maintenance" <?= $status_filter === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
            <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
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
                <?= $campus_filter == $campus['campus_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($campus['campus_name']) ?> (<?= htmlspecialchars($campus['campus_code']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      
      <div class="filter-group">
        <label for="faculty">Faculty</label>
        <div style="position:relative;">
          <i class="fas fa-university filter-icon"></i>
          <select id="faculty" name="faculty" class="filter-input">
            <option value="">All Faculties</option>
            <?php foreach($faculties as $faculty): ?>
              <option value="<?= $faculty['faculty_id'] ?>" 
                <?= $faculty_filter == $faculty['faculty_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($faculty['faculty_name']) ?> (<?= htmlspecialchars($faculty['faculty_code']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      
      <div class="filter-group">
        <label for="type">Room Type</label>
        <div style="position:relative;">
          <i class="fas fa-door-open filter-icon"></i>
          <select id="type" name="type" class="filter-input">
            <option value="">All Types</option>
            <option value="Lecture" <?= $type_filter === 'Lecture' ? 'selected' : '' ?>>Lecture Hall</option>
            <option value="Lab" <?= $type_filter === 'Lab' ? 'selected' : '' ?>>Laboratory</option>
            <option value="Seminar" <?= $type_filter === 'Seminar' ? 'selected' : '' ?>>Seminar Room</option>
            <option value="Office" <?= $type_filter === 'Office' ? 'selected' : '' ?>>Office</option>
            <option value="Online" <?= $type_filter === 'Online' ? 'selected' : '' ?>>Online</option>
            <option value="Computer" <?= $type_filter === 'Computer' ? 'selected' : '' ?>>Computer Lab</option>
            <option value="Library" <?= $type_filter === 'Library' ? 'selected' : '' ?>>Library</option>
            <option value="Meeting" <?= $type_filter === 'Meeting' ? 'selected' : '' ?>>Meeting Room</option>
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
      <h3>Room List</h3>
      <div class="results-count">
        Showing <?= count($rooms) ?> of <?= $total_rooms ?> rooms
        <?php if(!empty($search) || !empty($status_filter) || !empty($campus_filter) || !empty($faculty_filter) || !empty($type_filter)): ?>
          (filtered)
        <?php endif; ?>
      </div>
    </div>
    
    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Building</th>
            <th>Floor</th>
            <th>Room Name</th>
            <th>Code</th>
            <th>Capacity</th>
            <th>Campus</th>
            <th>Faculty</th>
            <th>Department</th>
            <th>Type</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if($rooms): ?>
            <?php foreach($rooms as $i=>$r): ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td><strong><?= htmlspecialchars($r['building_name']) ?></strong></td>
              <td><?= htmlspecialchars($r['floor_no']) ?></td>
              <td><?= htmlspecialchars($r['room_name']) ?></td>
              <td><span class="code-badge"><?= htmlspecialchars($r['room_code']) ?></span></td>
              <td><?= htmlspecialchars($r['capacity']) ?> seats</td>
              <td>
                <div><strong><?= htmlspecialchars($r['campus_name']) ?></strong></div>
                <div class="location-info">
                  <i class="fas fa-code"></i> <?= htmlspecialchars($r['campus_code']) ?>
                </div>
              </td>
              <td>
                <?php if($r['faculty_name']): ?>
                  <div><strong><?= htmlspecialchars($r['faculty_name']) ?></strong></div>
                  <div class="location-info">
                    <i class="fas fa-code"></i> <?= htmlspecialchars($r['faculty_code']) ?>
                  </div>
                <?php else: ?>
                  <span style="color: #999; font-style: italic;">Not assigned</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if($r['department_name']): ?>
                  <div><strong><?= htmlspecialchars($r['department_name']) ?></strong></div>
                  <div class="location-info">
                    <i class="fas fa-code"></i> <?= htmlspecialchars($r['department_code']) ?>
                  </div>
                <?php else: ?>
                  <span style="color: #999; font-style: italic;">Not assigned</span>
                <?php endif; ?>
              </td>
              <td>
                <?php
                  $type_icons = [
                    'Lecture' => 'fas fa-chalkboard-teacher',
                    'Lab' => 'fas fa-flask',
                    'Seminar' => 'fas fa-users',
                    'Office' => 'fas fa-user-tie',
                    'Online' => 'fas fa-laptop',
                    'Computer' => 'fas fa-desktop',
                    'Library' => 'fas fa-book',
                    'Meeting' => 'fas fa-handshake'
                  ];
                  $icon = $type_icons[$r['room_type']] ?? 'fas fa-door-closed';
                ?>
                <div style="display: flex; align-items: center; gap: 8px;">
                  <i class="<?= $icon ?>" style="color: var(--secondary-color);"></i>
                  <?= htmlspecialchars($r['room_type']) ?>
                </div>
              </td>
              <td>
                <span class="status-badge status-<?= $r['status'] ?>">
                  <?php
                    $status_labels = [
                      'available' => 'Available',
                      'occupied' => 'Occupied',
                      'maintenance' => 'Maintenance',
                      'inactive' => 'Inactive'
                    ];
                    echo $status_labels[$r['status']] ?? ucfirst($r['status']);
                  ?>
                </span>
              </td>
              <td>
                <div class="action-btns">
                  <button class="action-btn view-btn" 
                          onclick="openViewModal(<?= $r['room_id'] ?>)"
                          title="View Details">
                    <i class="fa-solid fa-eye"></i>
                  </button>
                  <button class="action-btn edit-btn" 
                          onclick="openEditModal(
                            <?= $r['room_id'] ?>,
                            '<?= $r['campus_id'] ?>',
                            '<?= $r['faculty_id'] ?>',
                            '<?= $r['department_id'] ?>',
                            '<?= htmlspecialchars($r['building_name'], ENT_QUOTES) ?>',
                            '<?= htmlspecialchars($r['floor_no'], ENT_QUOTES) ?>',
                            '<?= htmlspecialchars($r['room_name'], ENT_QUOTES) ?>',
                            '<?= htmlspecialchars($r['room_code'], ENT_QUOTES) ?>',
                            '<?= htmlspecialchars($r['capacity'], ENT_QUOTES) ?>',
                            '<?= htmlspecialchars($r['room_type'], ENT_QUOTES) ?>',
                            '<?= htmlspecialchars($r['description'], ENT_QUOTES) ?>',
                            '<?= $r['status'] ?>'
                          )"
                          title="Edit">
                    <i class="fa-solid fa-pen"></i>
                  </button>
                  <button class="action-btn del-btn" 
                          onclick="openDeleteModal(<?= $r['room_id'] ?>)" 
                          title="Delete">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="12">
                <div class="empty-state">
                  <i class="fa-solid fa-door-closed"></i>
                  <h3>No rooms found</h3>
                  <p>
                    <?php if(!empty($search) || !empty($status_filter) || !empty($campus_filter) || !empty($faculty_filter) || !empty($type_filter)): ?>
                      Try adjusting your filters or <a href="javascript:void(0)" onclick="clearFilters()">clear all filters</a>
                    <?php else: ?>
                      Add your first room using the button above
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
    <h2><i class="fas fa-eye"></i> Room Details</h2>
    
    <div class="view-content">
      <div class="view-details" id="view_details">
        <!-- Room details will be loaded here by JavaScript -->
      </div>
      
      <!-- Location Section -->
      <div class="location-section">
        <h4><i class="fas fa-map-marker-alt"></i> Location Information</h4>
        <div class="location-grid" id="view_location_grid">
          <!-- Location cards will be loaded here by JavaScript -->
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
    <h2><i class="fas fa-plus-circle"></i> Add New Room</h2>
    <form method="POST" id="addForm" onsubmit="return validateForm('add')">
      <input type="hidden" name="action" value="add">
      
      <div class="form-grid">
        <div class="form-group">
          <label for="building_name">Building Name *</label>
          <input type="text" id="building_name" name="building_name" class="form-control" required>
          <div class="error-message" id="building_name_error"></div>
        </div>
        
        <div class="form-group">
          <label for="floor_no">Floor Number</label>
          <input type="text" id="floor_no" name="floor_no" class="form-control">
        </div>
        
        <div class="form-group">
          <label for="room_name">Room Name *</label>
          <input type="text" id="room_name" name="room_name" class="form-control" required>
          <div class="error-message" id="room_name_error"></div>
        </div>
        
        <div class="form-group">
          <label for="room_code">Room Code *</label>
          <input type="text" id="room_code" name="room_code" class="form-control" required>
          <div class="error-message" id="room_code_error"></div>
        </div>
        
        <div class="form-group">
          <label for="capacity">Capacity</label>
          <input type="number" id="capacity" name="capacity" class="form-control" min="1">
        </div>
        
        <div class="form-group">
          <label for="room_type">Room Type *</label>
          <select id="room_type" name="room_type" class="form-control" required>
            <option value="">Select Type</option>
            <option value="Lecture">Lecture Hall</option>
            <option value="Lab">Laboratory</option>
            <option value="Seminar">Seminar Room</option>
            <option value="Office">Office</option>
            <option value="Online">Online</option>
            <option value="Computer">Computer Lab</option>
            <option value="Library">Library</option>
            <option value="Meeting">Meeting Room</option>
          </select>
          <div class="error-message" id="room_type_error"></div>
        </div>
        
        <div class="form-group">
          <label for="description">Description</label>
          <textarea id="description" name="description" class="form-control" rows="3"></textarea>
        </div>
      </div>
      
      <!-- Campus Selection -->
      <div class="selection-group">
        <label for="add_campus">Select Campus *</label>
        <select id="add_campus" name="campus_id" class="form-control" onchange="filterFaculties('add')" required>
          <option value="">Select Campus</option>
          <?php foreach($campuses as $c): ?>
            <option value="<?= $c['campus_id'] ?>">
              <?= htmlspecialchars($c['campus_name']) ?> (<?= htmlspecialchars($c['campus_code']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <div class="error-message" id="add_campus_error"></div>
      </div>
      
      <!-- Faculty Selection -->
      <div class="selection-group">
        <label for="add_faculty">Select Faculty *</label>
        <select id="add_faculty" name="faculty_id" class="form-control" onchange="filterDepartments('add')" required disabled>
          <option value="">Select Faculty</option>
        </select>
        <div class="error-message" id="add_faculty_error"></div>
      </div>
      
      <!-- Department Selection -->
      <div class="selection-group">
        <label for="add_department">Select Department (Optional)</label>
        <select id="add_department" name="department_id" class="form-control" disabled>
          <option value="">Select Department</option>
        </select>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-save"></i> Save Room
      </button>
    </form>
  </div>
</div>

<!-- ✅ EDIT MODAL -->
<div class="modal" id="editModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editModal')">&times;</span>
    <h2><i class="fas fa-edit"></i> Edit Room</h2>
    <form method="POST" id="editForm" onsubmit="return validateForm('edit')">
      <input type="hidden" name="action" value="update">
      <input type="hidden" id="edit_id" name="room_id">
      
      <div class="form-grid">
        <div class="form-group">
          <label for="edit_building">Building Name *</label>
          <input type="text" id="edit_building" name="building_name" class="form-control" required>
          <div class="error-message" id="edit_building_error"></div>
        </div>
        
        <div class="form-group">
          <label for="edit_floor">Floor Number</label>
          <input type="text" id="edit_floor" name="floor_no" class="form-control">
        </div>
        
        <div class="form-group">
          <label for="edit_roomname">Room Name *</label>
          <input type="text" id="edit_roomname" name="room_name" class="form-control" required>
          <div class="error-message" id="edit_roomname_error"></div>
        </div>
        
        <div class="form-group">
          <label for="edit_room_code">Room Code *</label>
          <input type="text" id="edit_room_code" name="room_code" class="form-control" required>
          <div class="error-message" id="edit_room_code_error"></div>
        </div>
        
        <div class="form-group">
          <label for="edit_capacity">Capacity</label>
          <input type="number" id="edit_capacity" name="capacity" class="form-control" min="1">
        </div>
        
        <div class="form-group">
          <label for="edit_type">Room Type *</label>
          <select id="edit_type" name="room_type" class="form-control" required>
            <option value="Lecture">Lecture Hall</option>
            <option value="Lab">Laboratory</option>
            <option value="Seminar">Seminar Room</option>
            <option value="Office">Office</option>
            <option value="Online">Online</option>
            <option value="Computer">Computer Lab</option>
            <option value="Library">Library</option>
            <option value="Meeting">Meeting Room</option>
          </select>
          <div class="error-message" id="edit_type_error"></div>
        </div>
        
        <div class="form-group">
          <label for="edit_desc">Description</label>
          <textarea id="edit_desc" name="description" class="form-control" rows="3"></textarea>
        </div>
        
        <div class="form-group">
          <label for="edit_status">Status *</label>
          <select id="edit_status" name="status" class="form-control" required>
            <option value="available">Available</option>
            <option value="occupied">Occupied</option>
            <option value="maintenance">Under Maintenance</option>
            <option value="inactive">Inactive</option>
          </select>
          <div class="error-message" id="edit_status_error"></div>
        </div>
      </div>
      
      <!-- Campus Selection for Edit -->
      <div class="selection-group">
        <label for="edit_campus">Select Campus *</label>
        <select id="edit_campus" name="campus_id" class="form-control" onchange="filterFaculties('edit')" required>
          <option value="">Select Campus</option>
          <?php foreach($campuses as $c): ?>
            <option value="<?= $c['campus_id'] ?>">
              <?= htmlspecialchars($c['campus_name']) ?> (<?= htmlspecialchars($c['campus_code']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <div class="error-message" id="edit_campus_error"></div>
      </div>
      
      <!-- Faculty Selection for Edit -->
      <div class="selection-group">
        <label for="edit_faculty">Select Faculty *</label>
        <select id="edit_faculty" name="faculty_id" class="form-control" onchange="filterDepartments('edit')" required disabled>
          <option value="">Select Faculty</option>
        </select>
        <div class="error-message" id="edit_faculty_error"></div>
      </div>
      
      <!-- Department Selection for Edit -->
      <div class="selection-group">
        <label for="edit_department">Select Department (Optional)</label>
        <select id="edit_department" name="department_id" class="form-control" disabled>
          <option value="">Select Department</option>
        </select>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-sync-alt"></i> Update Room
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
      <input type="hidden" name="room_id" id="delete_id">
      
      <div style="text-align: center; margin: 30px 0;">
        <i class="fa-solid fa-exclamation-triangle" style="font-size: 48px; color: var(--danger-color); margin-bottom: 20px;"></i>
        <p style="font-size: 16px; color: var(--dark-color); margin-bottom: 10px;">
          Are you sure you want to delete this room?
        </p>
        <p style="color: #666; font-size: 14px;">
          This action cannot be undone. All associated schedules will be removed.
        </p>
      </div>
      
      <button class="submit-btn delete-btn" type="submit">
        <i class="fas fa-trash-alt"></i> Yes, Delete Room
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
// Data for filtering
const faculties = <?= json_encode($faculties) ?>;
const departments = <?= json_encode($departments) ?>;
const facultyCampusMap = <?= json_encode($facultyCampusMap) ?>;

// Room data for view modal
const roomData = {
    <?php foreach($rooms as $r): ?>
    <?= $r['room_id'] ?>: {
        building: '<?= addslashes($r['building_name']) ?>',
        floor: '<?= addslashes($r['floor_no']) ?>',
        name: '<?= addslashes($r['room_name']) ?>',
        code: '<?= addslashes($r['room_code']) ?>',
        capacity: '<?= addslashes($r['capacity']) ?>',
        type: '<?= addslashes($r['room_type']) ?>',
        description: '<?= addslashes($r['description']) ?>',
        status: '<?= $r['status'] ?>',
        campus_id: <?= $r['campus_id'] ?>,
        campus_name: '<?= addslashes($r['campus_name']) ?>',
        campus_code: '<?= addslashes($r['campus_code']) ?>',
        faculty_id: <?= $r['faculty_id'] ?>,
        faculty_name: '<?= addslashes($r['faculty_name']) ?>',
        faculty_code: '<?= addslashes($r['faculty_code']) ?>',
        department_id: <?= $r['department_id'] ?>,
        department_name: '<?= addslashes($r['department_name']) ?>',
        department_code: '<?= addslashes($r['department_code']) ?>'
    },
    <?php endforeach; ?>
};

// Initialize Select2
$(document).ready(function() {
    $('#campus, #faculty, #status, #type').select2({
        placeholder: "Select option",
        allowClear: true,
        width: '100%'
    });
});

function filterFaculties(prefix) {
    const campusSelect = document.getElementById(prefix + '_campus');
    const facultySelect = document.getElementById(prefix + '_faculty');
    const deptSelect = document.getElementById(prefix + '_department');
    
    if (!campusSelect) return;
    
    const campusId = parseInt(campusSelect.value);
    
    // Clear faculty and department dropdowns
    facultySelect.innerHTML = '<option value="">Select Faculty</option>';
    deptSelect.innerHTML = '<option value="">Select Department (Optional)</option>';
    
    // Disable or enable based on campus selection
    if (!campusId) {
        facultySelect.disabled = true;
        deptSelect.disabled = true;
        return;
    }
    
    // Enable faculty dropdown
    facultySelect.disabled = false;
    
    // Filter faculties for selected campus
    faculties.forEach(f => {
        if (facultyCampusMap[f.faculty_id] && facultyCampusMap[f.faculty_id].includes(campusId)) {
            const option = document.createElement('option');
            option.value = f.faculty_id;
            option.textContent = f.faculty_name + ' (' + f.faculty_code + ')';
            facultySelect.appendChild(option);
        }
    });
    
    // Disable department dropdown until faculty is selected
    deptSelect.disabled = true;
}

function filterDepartments(prefix) {
    const campusSelect = document.getElementById(prefix + '_campus');
    const facultySelect = document.getElementById(prefix + '_faculty');
    const deptSelect = document.getElementById(prefix + '_department');
    
    if (!campusSelect || !facultySelect || !deptSelect) return;
    
    const campusId = parseInt(campusSelect.value);
    const facultyId = facultySelect.value;
    
    // Clear department dropdown
    deptSelect.innerHTML = '<option value="">Select Department (Optional)</option>';
    
    // Enable or disable based on campus and faculty selection
    if (!campusId || !facultyId) {
        deptSelect.disabled = true;
        return;
    }
    
    // Enable department dropdown
    deptSelect.disabled = false;
    
    // Filter departments for selected campus AND faculty
    departments.forEach(dept => {
        if (dept.campus_id == campusId && dept.faculty_id == facultyId) {
            const option = document.createElement('option');
            option.value = dept.department_id;
            option.textContent = dept.department_name + ' (' + dept.department_code + ')';
            deptSelect.appendChild(option);
        }
    });
}

function validateForm(prefix) {
    let isValid = true;
    
    // Campus validation
    const campusSelect = document.getElementById(prefix + '_campus');
    const campusError = document.getElementById(prefix + '_campus_error');
    if (!campusSelect.value) {
        campusSelect.classList.add('error');
        campusError.textContent = 'Please select a campus';
        campusError.style.display = 'block';
        isValid = false;
    } else {
        campusSelect.classList.remove('error');
        campusError.style.display = 'none';
    }
    
    // Faculty validation
    const facultySelect = document.getElementById(prefix + '_faculty');
    const facultyError = document.getElementById(prefix + '_faculty_error');
    if (!facultySelect.value) {
        facultySelect.classList.add('error');
        facultyError.textContent = 'Please select a faculty';
        facultyError.style.display = 'block';
        isValid = false;
    } else {
        facultySelect.classList.remove('error');
        facultyError.style.display = 'none';
    }
    
    // Building name validation
    const buildingInput = document.getElementById(prefix === 'add' ? 'building_name' : 'edit_building');
    const buildingError = document.getElementById(prefix === 'add' ? 'building_name_error' : 'edit_building_error');
    if (!buildingInput.value.trim()) {
        buildingInput.classList.add('error');
        buildingError.textContent = 'Please enter building name';
        buildingError.style.display = 'block';
        isValid = false;
    } else {
        buildingInput.classList.remove('error');
        buildingError.style.display = 'none';
    }
    
    // Room name validation
    const roomNameInput = document.getElementById(prefix === 'add' ? 'room_name' : 'edit_roomname');
    const roomNameError = document.getElementById(prefix === 'add' ? 'room_name_error' : 'edit_roomname_error');
    if (!roomNameInput.value.trim()) {
        roomNameInput.classList.add('error');
        roomNameError.textContent = 'Please enter room name';
        roomNameError.style.display = 'block';
        isValid = false;
    } else {
        roomNameInput.classList.remove('error');
        roomNameError.style.display = 'none';
    }
    
    // Room code validation
    const roomCodeInput = document.getElementById(prefix === 'add' ? 'room_code' : 'edit_room_code');
    const roomCodeError = document.getElementById(prefix === 'add' ? 'room_code_error' : 'edit_room_code_error');
    if (!roomCodeInput.value.trim()) {
        roomCodeInput.classList.add('error');
        roomCodeError.textContent = 'Please enter room code';
        roomCodeError.style.display = 'block';
        isValid = false;
    } else {
        roomCodeInput.classList.remove('error');
        roomCodeError.style.display = 'none';
    }
    
    // Room type validation
    const roomTypeSelect = document.getElementById(prefix === 'add' ? 'room_type' : 'edit_type');
    const roomTypeError = document.getElementById(prefix === 'add' ? 'room_type_error' : 'edit_type_error');
    if (!roomTypeSelect.value) {
        roomTypeSelect.classList.add('error');
        roomTypeError.textContent = 'Please select room type';
        roomTypeError.style.display = 'block';
        isValid = false;
    } else {
        roomTypeSelect.classList.remove('error');
        roomTypeError.style.display = 'none';
    }
    
    // For edit form only - status validation
    if (prefix === 'edit') {
        const statusSelect = document.getElementById('edit_status');
        const statusError = document.getElementById('edit_status_error');
        if (!statusSelect.value) {
            statusSelect.classList.add('error');
            statusError.textContent = 'Please select status';
            statusError.style.display = 'block';
            isValid = false;
        } else {
            statusSelect.classList.remove('error');
            statusError.style.display = 'none';
        }
    }
    
    return isValid;
}

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
        
        // Reset form
        if (id === 'addModal') {
            document.getElementById('addForm').reset();
            filterFaculties('add');
        }
    }
}

// Clear all filters
function clearFilters() {
    window.location.href = window.location.pathname;
}

// ✅ OPEN VIEW MODAL
function openViewModal(roomId) {
    openModal('viewModal');
    
    const room = roomData[roomId];
    if (!room) return;
    
    const typeIcons = {
        'Lecture': 'fas fa-chalkboard-teacher',
        'Lab': 'fas fa-flask',
        'Seminar': 'fas fa-users',
        'Office': 'fas fa-user-tie',
        'Online': 'fas fa-laptop',
        'Computer': 'fas fa-desktop',
        'Library': 'fas fa-book',
        'Meeting': 'fas fa-handshake'
    };
    
    const statusLabels = {
        'available': 'Available',
        'occupied': 'Occupied',
        'maintenance': 'Under Maintenance',
        'inactive': 'Inactive'
    };
    
    const icon = typeIcons[room.type] || 'fas fa-door-closed';
    
    // Set room details
    document.getElementById('view_details').innerHTML = `
        <div class="detail-item">
            <div class="detail-label"><i class="fas fa-building"></i> Building Name</div>
            <div class="detail-value">${room.building || 'Not provided'}</div>
        </div>
        
        <div class="detail-item">
            <div class="detail-label"><i class="fas fa-layer-group"></i> Floor Number</div>
            <div class="detail-value">${room.floor || 'Not specified'}</div>
        </div>
        
        <div class="detail-item">
            <div class="detail-label"><i class="fas fa-door-closed"></i> Room Name</div>
            <div class="detail-value">${room.name || 'Not provided'}</div>
        </div>
        
        <div class="detail-item">
            <div class="detail-label"><i class="fas fa-code"></i> Room Code</div>
            <div class="detail-value code">${room.code || 'Not provided'}</div>
        </div>
        
        <div class="detail-item">
            <div class="detail-label"><i class="fas fa-users"></i> Capacity</div>
            <div class="detail-value">${room.capacity ? room.capacity + ' seats' : 'Not specified'}</div>
        </div>
        
        <div class="detail-item">
            <div class="detail-label"><i class="${icon}"></i> Room Type</div>
            <div class="detail-value">${room.type || 'Not specified'}</div>
        </div>
        
        <div class="detail-item">
            <div class="detail-label"><i class="fas fa-circle"></i> Status</div>
            <div class="detail-value" style="border-left-color: ${
                room.status === 'available' ? 'var(--primary-color)' :
                room.status === 'occupied' ? 'var(--purple-color)' :
                room.status === 'maintenance' ? 'var(--warning-color)' : 'var(--danger-color)'
            };">
                ${statusLabels[room.status] || room.status || 'Not specified'}
            </div>
        </div>
        
        <div class="detail-item" style="grid-column: 1 / -1;">
            <div class="detail-label"><i class="fas fa-align-left"></i> Description</div>
            <div class="detail-value">${room.description || 'No description provided'}</div>
        </div>
    `;
    
    // Set location cards
    document.getElementById('view_location_grid').innerHTML = `
        <div class="location-card">
            <div class="location-card-header">
                <div class="location-card-name">${room.campus_name || 'Not assigned'}</div>
                <div class="location-card-code">${room.campus_code || ''}</div>
            </div>
            <div class="location-card-location">
                <i class="fas fa-map-marker-alt"></i> Campus
            </div>
        </div>
        
        <div class="location-card">
            <div class="location-card-header">
                <div class="location-card-name">${room.faculty_name || 'Not assigned'}</div>
                <div class="location-card-code">${room.faculty_code || ''}</div>
            </div>
            <div class="location-card-location">
                <i class="fas fa-university"></i> Faculty
            </div>
        </div>
        
        <div class="location-card">
            <div class="location-card-header">
                <div class="location-card-name">${room.department_name || 'Not assigned'}</div>
                <div class="location-card-code">${room.department_code || ''}</div>
            </div>
            <div class="location-card-location">
                <i class="fas fa-building"></i> Department
            </div>
        </div>
    `;
}

// ✅ OPEN EDIT MODAL
function openEditModal(id, campus, faculty, dept, building, floor, name, roomCode, capacity, type, desc, status) {
    openModal('editModal');
    
    // Set basic values
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_campus').value = campus;
    document.getElementById('edit_building').value = building;
    document.getElementById('edit_floor').value = floor;
    document.getElementById('edit_roomname').value = name;
    document.getElementById('edit_room_code').value = roomCode;
    document.getElementById('edit_capacity').value = capacity;
    document.getElementById('edit_type').value = type;
    document.getElementById('edit_desc').value = desc;
    document.getElementById('edit_status').value = status;
    
    // Filter faculties based on selected campus
    setTimeout(() => {
        filterFaculties('edit');
        
        // Set faculty after filtering
        setTimeout(() => {
            if (faculty) {
                document.getElementById('edit_faculty').value = faculty;
                
                // Filter departments based on selected campus and faculty
                filterDepartments('edit');
                
                // Set department after filtering
                setTimeout(() => {
                    if (dept) {
                        document.getElementById('edit_department').value = dept;
                    }
                }, 50);
            }
        }, 50);
    }, 50);
}

// ✅ OPEN DELETE MODAL
function openDeleteModal(id) {
    openModal('deleteModal');
    document.getElementById('delete_id').value = id;
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
    $('#status, #campus, #faculty, #type').on('change', function() {
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
    
    // Form validation on input change
    const forms = ['addForm', 'editForm'];
    forms.forEach(formId => {
        const form = document.getElementById(formId);
        if (form) {
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('change', function() {
                    // Remove error styling when user starts typing/selecting
                    this.classList.remove('error');
                    const errorElement = document.getElementById(this.id + '_error');
                    if (errorElement) {
                        errorElement.style.display = 'none';
                    }
                });
            });
        }
    });
});
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>