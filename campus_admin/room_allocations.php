<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Check login
if (empty($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

// ✅ Get user data
$user = $_SESSION['user'];
$role = strtolower(str_replace(' ', '_', $user['role'] ?? ''));
$user_id = $user['user_id'] ?? 0;
$linked_id = $user['linked_id'] ?? 0; // campus_id for campus admin

// ✅ Verify this is a campus admin
if ($role !== 'campus_admin') {
    header("Location: ../unauthorized.php");
    exit;
}

$name = htmlspecialchars($user['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars($user['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$phone = htmlspecialchars($user['phone_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$photo = !empty($user['profile_photo_path'])
    ? "../" . htmlspecialchars($user['profile_photo_path'], ENT_QUOTES, 'UTF-8')
    : "../upload/profiles/default.png";

// ✅ Get campus details
$campus_id = $linked_id;
$campus_name = '';
$campus_code = '';

if ($campus_id) {
    $stmt = $pdo->prepare("SELECT campus_name, campus_code FROM campus WHERE campus_id = ?");
    $stmt->execute([$campus_id]);
    $campus_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $campus_name = $campus_data['campus_name'] ?? 'Unknown Campus';
    $campus_code = $campus_data['campus_code'] ?? '';
}

$message = "";
$type = "";

/* =========================================================
   AJAX HANDLERS - Campus Admin Version
========================================================= */

// AJAX: Get rooms by campus, faculty, department (restricted to this campus)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_rooms') {
    header('Content-Type: application/json');
    
    try {
        $req_campus_id = $campus_id; // Force to current campus
        $req_faculty_id = $_GET['faculty_id'] ?? null;
        $req_department_id = $_GET['department_id'] ?? null;
        
        $sql = "
            SELECT r.*, c.campus_name 
            FROM rooms r
            LEFT JOIN campus c ON r.campus_id = c.campus_id
            WHERE r.status != 'inactive' AND r.campus_id = ?
        ";
        $params = [$campus_id];
        
        if (!empty($req_faculty_id)) {
            $sql .= " AND r.faculty_id = ?";
            $params[] = $req_faculty_id;
        }
        
        if (!empty($req_department_id)) {
            $sql .= " AND r.department_id = ?";
            $params[] = $req_department_id;
        }
        
        $sql .= " ORDER BY r.building_name, r.room_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'status' => 'success',
            'rooms' => $rooms
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// AJAX: Get faculties by campus (restricted to this campus)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_faculties_by_campus') {
    header('Content-Type: application/json');
    
    try {
        $req_campus_id = $campus_id; // Force to current campus
        
        if (empty($req_campus_id)) {
            echo json_encode(['status' => 'success', 'faculties' => []]);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT f.* 
            FROM faculties f
            INNER JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
            WHERE fc.campus_id = ?
            ORDER BY f.faculty_name
        ");
        $stmt->execute([$req_campus_id]);
        $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'status' => 'success',
            'faculties' => $faculties
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// AJAX: Get departments by campus and faculty (restricted to this campus)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_departments') {
    header('Content-Type: application/json');
    
    try {
        $req_campus_id = $campus_id; // Force to current campus
        $req_faculty_id = $_GET['faculty_id'] ?? null;
        
        $sql = "SELECT * FROM departments WHERE campus_id = ?";
        $params = [$campus_id];
        
        if (!empty($req_faculty_id)) {
            $sql .= " AND faculty_id = ?";
            $params[] = $req_faculty_id;
        }
        
        $sql .= " ORDER BY department_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'status' => 'success',
            'departments' => $departments
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// AJAX handler for getting allocation details (with campus check)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_allocation_details' && isset($_GET['allocation_id'])) {
    header('Content-Type: application/json');
    
    try {
        $allocation_id = $_GET['allocation_id'];
        
        $query = "
            SELECT 
                ra.*,
                r.room_name,
                r.room_code,
                r.building_name,
                r.capacity as room_capacity,
                r.campus_id,
                r.faculty_id,
                r.department_id,
                c.campus_name,
                c.campus_code,
                at.term_name,
                ay.year_name as academic_year,
                d.department_name,
                d.department_code,
                f.faculty_id,
                f.faculty_name,
                f.faculty_code
            FROM room_allocation ra
            LEFT JOIN rooms r ON ra.room_id = r.room_id
            LEFT JOIN campus c ON ra.campus_id = c.campus_id
            LEFT JOIN academic_term at ON ra.academic_term_id = at.academic_term_id
            LEFT JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
            LEFT JOIN departments d ON ra.department_id = d.department_id
            LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
            WHERE ra.allocation_id = ? AND ra.campus_id = ?
        ";
        
        $params = [$allocation_id, $campus_id];
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $allocation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'status' => 'success',
            'allocation' => $allocation
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// AJAX handler for getting room allocations (restricted to this campus)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_room_allocations' && isset($_GET['room_id'])) {
    header('Content-Type: application/json');
    
    try {
        $room_id = $_GET['room_id'];
        
        $stmt = $pdo->prepare("
            SELECT 
                ra.*,
                at.term_name,
                ay.year_name as academic_year,
                d.department_name,
                c.campus_name
            FROM room_allocation ra
            LEFT JOIN academic_term at ON ra.academic_term_id = at.academic_term_id
            LEFT JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
            LEFT JOIN departments d ON ra.department_id = d.department_id
            LEFT JOIN campus c ON ra.campus_id = c.campus_id
            WHERE ra.room_id = ? AND ra.campus_id = ?
            ORDER BY ra.start_date DESC
        ");
        $stmt->execute([$room_id, $campus_id]);
        $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $current_date = date('Y-m-d');
        foreach ($allocations as &$allocation) {
            $allocation['is_current'] = ($allocation['start_date'] <= $current_date && 
                ($allocation['end_date'] === null || $allocation['end_date'] >= $current_date)) ? 1 : 0;
        }
        
        echo json_encode([
            'status' => 'success',
            'allocations' => $allocations
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

/* =========================================================
   CRUD OPERATIONS FOR ALLOCATIONS - Campus Admin Version
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 🟢 ADD ALLOCATION
    if ($action === 'add_allocation') {
        try {
            // Verify allocation belongs to this campus
            $dept_id = $_POST['department_id'] ?? null;
            
            if ($dept_id) {
                $check = $pdo->prepare("SELECT campus_id FROM departments WHERE department_id = ?");
                $check->execute([$dept_id]);
                $dept_campus = $check->fetchColumn();
                if ($dept_campus != $campus_id) {
                    throw new Exception("You don't have permission to create allocations for this department");
                }
            }
            
            $pdo->beginTransaction();
            
            $room_id = $_POST['room_id'];
            $academic_term_id = $_POST['academic_term_id'];
            $department_id_val = $_POST['department_id'] ?? null;
            $allocated_to = $_POST['allocated_to'];
            $start_date = $_POST['start_date'];
            $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $remarks = $_POST['remarks'] ?? null;
            $student_count = $_POST['student_count'] ?? 0;
            $status = 'active'; // Default status for new allocations
            
            if (empty($room_id) || empty($academic_term_id) || empty($start_date)) {
                throw new Exception("Room, Academic Term, and Start Date are required");
            }
            
            // Verify room belongs to this campus
            $stmt = $pdo->prepare("SELECT campus_id, capacity FROM rooms WHERE room_id = ?");
            $stmt->execute([$room_id]);
            $roomData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($roomData['campus_id'] != $campus_id) {
                throw new Exception("You don't have permission to use this room");
            }
            
            if (!empty($student_count) && $student_count > $roomData['capacity']) {
                throw new Exception("Student count ($student_count) cannot exceed room capacity ({$roomData['capacity']})");
            }
            
            // Check for overlapping allocations
            $check = $pdo->prepare("
                SELECT COUNT(*) FROM room_allocation 
                WHERE room_id = ? 
                AND (
                    (start_date <= ? AND (end_date >= ? OR end_date IS NULL))
                    OR (start_date <= ? AND (end_date >= ? OR end_date IS NULL))
                )
                AND status = 'active'
            ");
            
            $check->execute([
                $room_id, 
                $start_date, $start_date,
                $end_date ?? $start_date, $end_date ?? $start_date
            ]);
            
            if ($check->fetchColumn() > 0) {
                throw new Exception("This room is already allocated for the selected date range!");
            }
            
            // Insert allocation
            $stmt = $pdo->prepare("
                INSERT INTO room_allocation 
                (room_id, campus_id, academic_term_id, department_id, allocated_to, 
                 start_date, end_date, remarks, student_count, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $room_id, $campus_id, $academic_term_id, $department_id_val, $allocated_to,
                $start_date, $end_date, $remarks, $student_count, $status
            ]);
            
            // Update room status if allocation starts today or earlier
            $current_date = date('Y-m-d');
            if ($start_date <= $current_date && ($end_date === null || $end_date >= $current_date)) {
                $updateRoom = $pdo->prepare("UPDATE rooms SET status = 'occupied' WHERE room_id = ?");
                $updateRoom->execute([$room_id]);
            }
            
            $pdo->commit();
            $message = "✅ Room allocation created successfully!";
            $type = "success";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }
    
    // 🟡 UPDATE ALLOCATION
    if ($action === 'update_allocation') {
        try {
            $allocation_id = $_POST['allocation_id'];
            
            // Verify allocation belongs to this campus
            $check = $pdo->prepare("SELECT campus_id, room_id FROM room_allocation WHERE allocation_id = ?");
            $check->execute([$allocation_id]);
            $alloc_data = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$alloc_data || $alloc_data['campus_id'] != $campus_id) {
                throw new Exception("You don't have permission to update this allocation");
            }
            
            $pdo->beginTransaction();
            
            $room_id = $_POST['room_id'];
            $academic_term_id = $_POST['academic_term_id'];
            $department_id_val = $_POST['department_id'] ?? null;
            $allocated_to = $_POST['allocated_to'];
            $start_date = $_POST['start_date'];
            $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
            $remarks = $_POST['remarks'] ?? null;
            $student_count = $_POST['student_count'] ?? 0;
            $status = $_POST['status'];
            
            // Validate student count is provided
            if ($student_count === '' || $student_count === null) {
                $student_count = 0;
            }
            
            // Verify room belongs to this campus
            $stmt = $pdo->prepare("SELECT campus_id, capacity FROM rooms WHERE room_id = ?");
            $stmt->execute([$room_id]);
            $roomData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($roomData['campus_id'] != $campus_id) {
                throw new Exception("You don't have permission to use this room");
            }
            
            if (!empty($student_count) && $student_count > $roomData['capacity']) {
                throw new Exception("Student count ($student_count) cannot exceed room capacity ({$roomData['capacity']})");
            }
            
            // Check for overlapping allocations (excluding current one)
            $check = $pdo->prepare("
                SELECT COUNT(*) FROM room_allocation 
                WHERE room_id = ? AND allocation_id != ?
                AND (
                    (start_date <= ? AND (end_date >= ? OR end_date IS NULL))
                    OR (start_date <= ? AND (end_date >= ? OR end_date IS NULL))
                )
                AND status = 'active'
            ");
            
            $check->execute([
                $room_id, $allocation_id,
                $start_date, $start_date,
                $end_date ?? $start_date, $end_date ?? $start_date
            ]);
            
            if ($check->fetchColumn() > 0) {
                throw new Exception("This room is already allocated for the selected date range!");
            }
            
            // Update allocation
            $stmt = $pdo->prepare("
                UPDATE room_allocation 
                SET academic_term_id = ?, 
                    department_id = ?, 
                    allocated_to = ?, 
                    start_date = ?, 
                    end_date = ?, 
                    remarks = ?, 
                    student_count = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE allocation_id = ?
            ");
            
            $stmt->execute([
                $academic_term_id, $department_id_val, $allocated_to,
                $start_date, $end_date, $remarks, $student_count,
                $status, $allocation_id
            ]);
            
            // Update room status based on active allocations
            $current_date = date('Y-m-d');
            $checkActive = $pdo->prepare("
                SELECT COUNT(*) FROM room_allocation 
                WHERE room_id = ? 
                AND status = 'active'
                AND start_date <= ?
                AND (end_date >= ? OR end_date IS NULL)
            ");
            $checkActive->execute([$room_id, $current_date, $current_date]);
            
            $roomStatus = $checkActive->fetchColumn() > 0 ? 'occupied' : 'available';
            $updateRoom = $pdo->prepare("UPDATE rooms SET status = ? WHERE room_id = ?");
            $updateRoom->execute([$roomStatus, $room_id]);
            
            $pdo->commit();
            $message = "✅ Allocation updated successfully!";
            $type = "success";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }
    
    // 🔴 DELETE ALLOCATION
    if ($action === 'delete_allocation') {
        try {
            $allocation_id = $_POST['allocation_id'];
            
            // Verify allocation belongs to this campus
            $check = $pdo->prepare("SELECT campus_id, room_id FROM room_allocation WHERE allocation_id = ?");
            $check->execute([$allocation_id]);
            $alloc = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$alloc || $alloc['campus_id'] != $campus_id) {
                throw new Exception("You don't have permission to delete this allocation");
            }
            
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("DELETE FROM room_allocation WHERE allocation_id = ?");
            $stmt->execute([$allocation_id]);
            
            if ($alloc['room_id']) {
                $current_date = date('Y-m-d');
                $checkActive = $pdo->prepare("
                    SELECT COUNT(*) FROM room_allocation 
                    WHERE room_id = ? 
                    AND status = 'active'
                    AND start_date <= ?
                    AND (end_date >= ? OR end_date IS NULL)
                ");
                $checkActive->execute([$alloc['room_id'], $current_date, $current_date]);
                
                $roomStatus = $checkActive->fetchColumn() > 0 ? 'occupied' : 'available';
                $updateRoom = $pdo->prepare("UPDATE rooms SET status = ? WHERE room_id = ?");
                $updateRoom->execute([$roomStatus, $alloc['room_id']]);
            }
            
            $pdo->commit();
            $message = "✅ Allocation deleted successfully!";
            $type = "success";
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "❌ " . $e->getMessage();
            $type = "error";
        }
    }
}

/* =========================================================
   FETCH DATA FOR DISPLAY - Campus Admin Version
========================================================= */

$search = $_GET['search'] ?? '';
$faculty_filter = $_GET['faculty'] ?? '';
$room_filter = $_GET['room'] ?? '';
$term_filter = $_GET['term'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Fetch faculties for this campus
$faculties = [];
$stmt = $pdo->prepare("
    SELECT DISTINCT f.* 
    FROM faculties f
    INNER JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
    WHERE fc.campus_id = ?
    ORDER BY f.faculty_name
");
$stmt->execute([$campus_id]);
$faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch departments for this campus
$departments = [];
$deptQuery = "SELECT department_id, department_name, department_code, faculty_id, campus_id FROM departments WHERE campus_id = ?";
$deptParams = [$campus_id];

if (!empty($faculty_filter)) {
    $deptQuery .= " AND faculty_id = ?";
    $deptParams[] = $faculty_filter;
}

$deptQuery .= " ORDER BY department_name";
$stmt = $pdo->prepare($deptQuery);
$stmt->execute($deptParams);
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all rooms for this campus
$roomQuery = "
    SELECT r.*, c.campus_name, f.faculty_name, d.department_name
    FROM rooms r
    LEFT JOIN campus c ON r.campus_id = c.campus_id
    LEFT JOIN faculties f ON r.faculty_id = f.faculty_id
    LEFT JOIN departments d ON r.department_id = d.department_id
    WHERE r.status != 'inactive' AND r.campus_id = ?
";
$roomParams = [$campus_id];

if (!empty($faculty_filter)) {
    $roomQuery .= " AND r.faculty_id = ?";
    $roomParams[] = $faculty_filter;
}

$roomQuery .= " ORDER BY f.faculty_name, d.department_name, r.building_name, r.room_name";

$stmt = $pdo->prepare($roomQuery);
$stmt->execute($roomParams);
$allRooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch academic terms (all active terms)
$academic_terms = $pdo->query("
    SELECT at.academic_term_id, at.term_name, ay.year_name 
    FROM academic_term at
    LEFT JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
    WHERE at.status = 'active'
    ORDER BY ay.year_name DESC, at.term_name
")->fetchAll(PDO::FETCH_ASSOC);

// Build main query for allocations (restricted to this campus)
$query = "
    SELECT 
        ra.*,
        r.room_name,
        r.room_code,
        r.building_name,
        r.capacity as room_capacity,
        r.campus_id,
        r.faculty_id,
        r.department_id,
        c.campus_name,
        c.campus_code,
        f.faculty_name,
        f.faculty_code,
        d.department_name,
        d.department_code,
        at.term_name,
        ay.year_name as academic_year
    FROM room_allocation ra
    LEFT JOIN rooms r ON ra.room_id = r.room_id
    LEFT JOIN campus c ON ra.campus_id = c.campus_id
    LEFT JOIN faculties f ON r.faculty_id = f.faculty_id
    LEFT JOIN departments d ON ra.department_id = d.department_id
    LEFT JOIN academic_term at ON ra.academic_term_id = at.academic_term_id
    LEFT JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
    WHERE ra.campus_id = ?
";

$params = [$campus_id];

if (!empty($search)) {
    $query .= " AND (r.room_name LIKE ? OR r.room_code LIKE ? OR at.term_name LIKE ? OR d.department_name LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
}

if (!empty($faculty_filter)) {
    $query .= " AND r.faculty_id = ?";
    $params[] = $faculty_filter;
}

if (!empty($room_filter)) {
    $query .= " AND ra.room_id = ?";
    $params[] = $room_filter;
}

if (!empty($term_filter)) {
    $query .= " AND ra.academic_term_id = ?";
    $params[] = $term_filter;
}

if (!empty($status_filter)) {
    $query .= " AND ra.status = ?";
    $params[] = $status_filter;
}

if (!empty($date_from)) {
    $query .= " AND ra.start_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $query .= " AND ra.start_date <= ?";
    $params[] = $date_to;
}

$query .= " ORDER BY ra.start_date DESC, ra.allocation_id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process allocations to ensure status and counts are set
$current_date = date('Y-m-d');
foreach($allocations as &$a) {
    // Ensure status is not empty
    if (empty($a['status'])) {
        if ($a['end_date'] < $current_date) {
            $a['status'] = 'completed';
        } elseif ($a['start_date'] > $current_date) {
            $a['status'] = 'active'; // upcoming
        } else {
            $a['status'] = 'active'; // current
        }
    }
    
    // Ensure student_count is not null
    if ($a['student_count'] === null) {
        $a['student_count'] = 0;
    }
    
    // Calculate display status for UI
    if ($a['status'] === 'active') {
        if ($a['end_date'] < $current_date) {
            $a['display_status'] = 'completed';
            $a['display_text'] = 'Completed';
        } elseif ($a['start_date'] > $current_date) {
            $a['display_status'] = 'upcoming';
            $a['display_text'] = 'Upcoming';
        } else {
            $a['display_status'] = 'current';
            $a['display_text'] = 'Current';
        }
    } elseif ($a['status'] === 'inactive') {
        $a['display_status'] = 'inactive';
        $a['display_text'] = 'Cancelled';
    } elseif ($a['status'] === 'completed') {
        $a['display_status'] = 'completed';
        $a['display_text'] = 'Completed';
    } else {
        $a['display_status'] = $a['status'];
        $a['display_text'] = ucfirst($a['status']);
    }
}

// Stats for this campus
$statsQuery = "SELECT COUNT(*) FROM room_allocation WHERE campus_id = ?";
$statsParams = [$campus_id];

$total_allocations_stmt = $pdo->prepare($statsQuery);
$total_allocations_stmt->execute($statsParams);
$total_allocations = $total_allocations_stmt->fetchColumn();

$activeQuery = $statsQuery . " AND status = 'active' AND start_date <= CURDATE() AND (end_date >= CURDATE() OR end_date IS NULL)";
$active_stmt = $pdo->prepare($activeQuery);
$active_stmt->execute($statsParams);
$active_allocations = $active_stmt->fetchColumn();

$upcomingQuery = $statsQuery . " AND start_date > CURDATE()";
$upcoming_stmt = $pdo->prepare($upcomingQuery);
$upcoming_stmt->execute($statsParams);
$upcoming_allocations = $upcoming_stmt->fetchColumn();

$pastQuery = $statsQuery . " AND end_date < CURDATE()";
$past_stmt = $pdo->prepare($pastQuery);
$past_stmt->execute($statsParams);
$past_allocations = $past_stmt->fetchColumn();

$classQuery = $statsQuery . " AND allocated_to = 'Class'";
$class_stmt = $pdo->prepare($classQuery);
$class_stmt->execute($statsParams);
$class_allocations = $class_stmt->fetchColumn();

$examQuery = $statsQuery . " AND allocated_to = 'Exam'";
$exam_stmt = $pdo->prepare($examQuery);
$exam_stmt->execute($statsParams);
$exam_allocations = $exam_stmt->fetchColumn();

$maintenanceQuery = $statsQuery . " AND allocated_to = 'Maintenance'";
$maintenance_stmt = $pdo->prepare($maintenanceQuery);
$maintenance_stmt->execute($statsParams);
$maintenance_allocations = $maintenance_stmt->fetchColumn();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Room Allocation Management - <?= htmlspecialchars($campus_name) ?> | Hormuud University</title>
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<style>
:root {
  --hormuud-green: #00A859;
  --hormuud-dark-green: #00843D;
  --hormuud-light-green: #4CAF50;
  --hormuud-blue: #0072CE;
  --hormuud-gold: #FFB81C;
  --hormuud-orange: #F57C00;
  --hormuud-red: #C62828;
  --hormuud-gray: #F5F5F5;
  --hormuud-dark: #2C3E50;
  --hormuud-white: #FFFFFF;
  --hormuud-black: #212121;
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: 'Poppins', sans-serif;
}

body {
  background: var(--hormuud-gray);
  color: var(--hormuud-dark);
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

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 25px;
  padding: 20px 25px;
  background: var(--hormuud-white);
  border-radius: 12px;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
  border-left: 6px solid var(--hormuud-green);
}

.page-header h1 {
  color: var(--hormuud-dark);
  font-size: 26px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.page-header h1 i {
  color: var(--hormuud-white);
  background: var(--hormuud-green);
  padding: 12px;
  border-radius: 10px;
  box-shadow: 0 4px 10px rgba(0, 168, 89, 0.2);
}

.campus-badge {
  font-size: 14px;
  font-weight: 400;
  background: rgba(0, 114, 206, 0.1);
  color: var(--hormuud-blue);
  padding: 5px 15px;
  border-radius: 20px;
  margin-left: 15px;
}

.campus-badge i {
  margin-right: 5px;
  color: var(--hormuud-blue);
}

.add-btn {
  background: linear-gradient(135deg, var(--hormuud-green), var(--hormuud-dark-green));
  color: var(--hormuud-white);
  border: none;
  padding: 12px 24px;
  border-radius: 8px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  gap: 10px;
  box-shadow: 0 4px 12px rgba(0, 168, 89, 0.3);
}

.add-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 6px 20px rgba(0, 168, 89, 0.4);
}

.back-btn {
  background: linear-gradient(135deg, var(--hormuud-blue), #005fa3);
  color: var(--hormuud-white);
  border: none;
  padding: 12px 24px;
  border-radius: 8px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  gap: 10px;
  box-shadow: 0 4px 12px rgba(0, 114, 206, 0.3);
  text-decoration: none;
}

.back-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 6px 20px rgba(0, 114, 206, 0.4);
}

.campus-info-bar {
  background: linear-gradient(135deg, rgba(0, 114, 206, 0.05), rgba(0, 168, 89, 0.05));
  border-radius: 12px;
  padding: 15px 25px;
  margin-bottom: 25px;
  display: flex;
  align-items: center;
  gap: 20px;
  flex-wrap: wrap;
  border: 1px solid rgba(0, 114, 206, 0.2);
  border-left: 6px solid var(--hormuud-blue);
}

.campus-info-item {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 14px;
  color: var(--hormuud-dark);
}

.campus-info-item i {
  color: var(--hormuud-blue);
  font-size: 16px;
  width: 20px;
}

.campus-info-item strong {
  color: var(--hormuud-green);
  font-weight: 600;
  margin-left: 5px;
}

.stats-container {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
  margin-bottom: 25px;
}

.stat-card {
  background: var(--hormuud-white);
  border-radius: 12px;
  padding: 20px;
  display: flex;
  align-items: center;
  gap: 20px;
  box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
  transition: transform 0.3s ease;
  border-bottom: 4px solid;
}

.stat-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
}

.stat-card.total { border-bottom-color: var(--hormuud-blue); }
.stat-card.active { border-bottom-color: var(--hormuud-green); }
.stat-card.upcoming { border-bottom-color: var(--hormuud-gold); }
.stat-card.past { border-bottom-color: var(--hormuud-orange); }
.stat-card.class { border-bottom-color: var(--hormuud-blue); }
.stat-card.exam { border-bottom-color: var(--hormuud-green); }
.stat-card.maintenance { border-bottom-color: var(--hormuud-orange); }

.stat-icon {
  width: 55px;
  height: 55px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  color: var(--hormuud-white);
}

.stat-icon.total { background: var(--hormuud-blue); }
.stat-icon.active { background: var(--hormuud-green); }
.stat-icon.upcoming { background: var(--hormuud-gold); }
.stat-icon.past { background: var(--hormuud-orange); }
.stat-icon.class { background: var(--hormuud-blue); }
.stat-icon.exam { background: var(--hormuud-green); }
.stat-icon.maintenance { background: var(--hormuud-orange); }

.stat-info h3 {
  font-size: 14px;
  color: #666;
  margin-bottom: 5px;
  font-weight: 500;
}

.stat-info .number {
  font-size: 28px;
  font-weight: 700;
  color: var(--hormuud-dark);
  line-height: 1;
}

.filters-container {
  background: var(--hormuud-white);
  border-radius: 12px;
  padding: 25px;
  margin-bottom: 25px;
  box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
  border-top: 4px solid var(--hormuud-gold);
}

.filter-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.filter-header h3 {
  color: var(--hormuud-dark);
  font-size: 18px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 10px;
}

.filter-header h3 i {
  color: var(--hormuud-gold);
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
  color: var(--hormuud-dark);
  font-size: 13px;
}

.filter-input {
  width: 100%;
  padding: 12px 15px 12px 45px;
  border: 1.5px solid #e0e0e0;
  border-radius: 8px;
  font-size: 14px;
  transition: all 0.3s ease;
  background: #fafafa;
}

.filter-input:focus {
  outline: none;
  border-color: var(--hormuud-green);
  box-shadow: 0 0 0 3px rgba(0, 168, 89, 0.1);
  background: var(--hormuud-white);
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
  background: var(--hormuud-green);
  color: var(--hormuud-white);
}

.apply-btn:hover {
  background: var(--hormuud-dark-green);
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(0, 168, 89, 0.3);
}

.clear-btn {
  background: #6c757d;
  color: var(--hormuud-white);
}

.clear-btn:hover {
  background: #5a6268;
  transform: translateY(-2px);
}

.table-wrapper {
  background: var(--hormuud-white);
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
  margin-bottom: 30px;
  border: 1px solid #eee;
}

.table-header {
  padding: 20px 25px;
  border-bottom: 1px solid #eee;
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: linear-gradient(to right, #f9f9f9, var(--hormuud-white));
}

.table-header h3 {
  color: var(--hormuud-dark);
  font-size: 16px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 8px;
}

.table-header h3 i {
  color: var(--hormuud-green);
}

.results-count {
  color: #666;
  font-size: 14px;
  background: #f0f0f0;
  padding: 5px 12px;
  border-radius: 20px;
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
  background: linear-gradient(135deg, var(--hormuud-green), var(--hormuud-dark-green));
}

.data-table th {
  padding: 16px 20px;
  text-align: left;
  font-weight: 600;
  color: var(--hormuud-white);
  white-space: nowrap;
  border-bottom: 2px solid rgba(255, 255, 255, 0.1);
}

.data-table td {
  padding: 14px 20px;
  border-bottom: 1px solid #eee;
  vertical-align: middle;
}

.data-table tbody tr:hover {
  background: rgba(0, 168, 89, 0.05);
}

.data-table tbody tr:nth-child(even) {
  background: #fafafa;
}

.room-badge {
  display: inline-block;
  background: rgba(0, 114, 206, 0.1);
  color: var(--hormuud-blue);
  padding: 6px 12px;
  border-radius: 20px;
  font-weight: 600;
  font-size: 13px;
}

.room-code {
  font-family: 'Courier New', monospace;
  color: #666;
  font-size: 11px;
  margin-top: 4px;
}

.allocation-type {
  display: inline-block;
  padding: 6px 15px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.type-Class {
  background: rgba(0, 114, 206, 0.1);
  color: var(--hormuud-blue);
  border: 1px solid rgba(0, 114, 206, 0.2);
}

.type-Exam {
  background: rgba(0, 168, 89, 0.1);
  color: var(--hormuud-green);
  border: 1px solid rgba(0, 168, 89, 0.2);
}

.type-Maintenance {
  background: rgba(255, 184, 28, 0.1);
  color: var(--hormuud-gold);
  border: 1px solid rgba(255, 184, 28, 0.2);
}

.status-badge {
  padding: 6px 15px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  display: inline-block;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.status-active {
  background: rgba(0, 168, 89, 0.1);
  color: var(--hormuud-green);
  border: 1px solid rgba(0, 168, 89, 0.2);
}

.status-inactive {
  background: rgba(198, 40, 40, 0.1);
  color: var(--hormuud-red);
  border: 1px solid rgba(198, 40, 40, 0.2);
}

.status-completed {
  background: #e0e0e0;
  color: #666;
  border: 1px solid rgba(0, 0, 0, 0.1);
}

.capacity-warning {
  color: var(--hormuud-red);
  font-weight: 700;
  background: rgba(198, 40, 40, 0.1);
  padding: 4px 8px;
  border-radius: 4px;
}

.capacity-normal {
  color: var(--hormuud-green);
  font-weight: 600;
}

.capacity-full {
  color: var(--hormuud-orange);
  font-weight: 600;
}

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
  background: var(--hormuud-blue);
  color: var(--hormuud-white);
}

.view-btn:hover {
  background: #005fa3;
  transform: translateY(-2px);
  box-shadow: 0 5px 12px rgba(0, 114, 206, 0.3);
}

.edit-btn {
  background: var(--hormuud-green);
  color: var(--hormuud-white);
}

.edit-btn:hover {
  background: var(--hormuud-dark-green);
  transform: translateY(-2px);
  box-shadow: 0 5px 12px rgba(0, 168, 89, 0.3);
}

.del-btn {
  background: var(--hormuud-red);
  color: var(--hormuud-white);
}

.del-btn:hover {
  background: #b71c1c;
  transform: translateY(-2px);
  box-shadow: 0 5px 12px rgba(198, 40, 40, 0.3);
}

.action-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  pointer-events: none;
}

.modal {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
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
  background: var(--hormuud-white);
  border-radius: 16px;
  width: 100%;
  max-width: 900px;
  max-height: 90vh;
  overflow-y: auto;
  padding: 35px;
  position: relative;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
  border-top: 6px solid var(--hormuud-green);
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
  color: var(--hormuud-red);
  transform: rotate(90deg);
}

.modal-content h2 {
  color: var(--hormuud-dark);
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
  color: var(--hormuud-white);
  background: var(--hormuud-green);
  padding: 10px;
  border-radius: 10px;
}

.form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 25px;
  margin-bottom: 25px;
}

.form-group {
  margin-bottom: 20px;
}

.form-group label {
  display: block;
  margin-bottom: 8px;
  font-weight: 500;
  color: var(--hormuud-dark);
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
  background: var(--hormuud-green);
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
  border-color: var(--hormuud-green);
  background: var(--hormuud-white);
  box-shadow: 0 0 0 4px rgba(0, 168, 89, 0.1);
  transform: translateY(-2px);
}

.form-control:disabled {
  background: #f0f0f0;
  cursor: not-allowed;
  opacity: 0.6;
}

.selection-group {
  grid-column: 1 / -1;
  margin: 15px 0;
  padding: 20px;
  background: #f9fff9;
  border-radius: 10px;
  border: 2px solid rgba(0, 168, 89, 0.2);
}

.selection-group label {
  font-weight: 600;
  color: var(--hormuud-green);
  margin-bottom: 15px;
  display: block;
  font-size: 16px;
}

.selection-group label i {
  margin-right: 8px;
}

.submit-btn {
  grid-column: 1 / -1;
  background: linear-gradient(135deg, var(--hormuud-green), var(--hormuud-dark-green));
  color: var(--hormuud-white);
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
  box-shadow: 0 6px 20px rgba(0, 168, 89, 0.3);
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
  box-shadow: 0 10px 25px rgba(0, 168, 89, 0.4);
}

.submit-btn:hover::before {
  left: 100%;
}

.submit-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  pointer-events: none;
}

.delete-btn {
  background: linear-gradient(135deg, var(--hormuud-red), #b71c1c);
}

.error-message {
  color: var(--hormuud-red);
  font-size: 12px;
  margin-top: 5px;
  display: none;
  background: rgba(198, 40, 40, 0.1);
  padding: 8px 12px;
  border-radius: 6px;
  border-left: 3px solid var(--hormuud-red);
}

.form-control.error {
  border-color: var(--hormuud-red);
  background: rgba(198, 40, 40, 0.05);
}

.capacity-indicator {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
  margin-left: 10px;
}

.capacity-indicator.available {
  background: rgba(0, 168, 89, 0.1);
  color: var(--hormuud-green);
}

.capacity-indicator.low {
  background: rgba(255, 184, 28, 0.1);
  color: var(--hormuud-gold);
}

.capacity-indicator.full {
  background: rgba(198, 40, 40, 0.1);
  color: var(--hormuud-red);
}

.view-details {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 20px;
}

.detail-item {
  padding: 15px;
  background: #f9f9f9;
  border-radius: 10px;
  border-left: 4px solid var(--hormuud-green);
}

.detail-label {
  font-size: 12px;
  color: #666;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 8px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.detail-label i {
  color: var(--hormuud-green);
  width: 16px;
}

.detail-value {
  font-size: 16px;
  color: var(--hormuud-dark);
  font-weight: 500;
}

.loading-spinner {
  text-align: center;
  padding: 40px;
}

.loading-spinner i {
  font-size: 48px;
  color: var(--hormuud-green);
  margin-bottom: 15px;
}

.loading-spinner p {
  color: #666;
  font-size: 14px;
}

.empty-state {
  text-align: center;
  padding: 80px 20px;
  color: #666;
}

.empty-state i {
  font-size: 64px;
  margin-bottom: 25px;
  color: var(--hormuud-green);
  opacity: 0.5;
  display: block;
}

.empty-state h3 {
  font-size: 20px;
  margin-bottom: 15px;
  color: var(--hormuud-dark);
}

.empty-state p {
  color: #999;
}

.alert-popup {
  display: none;
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: var(--hormuud-white);
  border-radius: 12px;
  padding: 30px 35px;
  text-align: center;
  z-index: 1100;
  box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
  min-width: 350px;
  animation: alertSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
  border-top: 6px solid;
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
  border-top-color: var(--hormuud-green);
}

.alert-popup.error {
  border-top-color: var(--hormuud-red);
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
  background: rgba(0, 168, 89, 0.1);
  color: var(--hormuud-green);
}

.alert-popup.error .alert-icon {
  background: rgba(198, 40, 40, 0.1);
  color: var(--hormuud-red);
}

.alert-message {
  color: var(--hormuud-dark);
  font-size: 16px;
  font-weight: 500;
  line-height: 1.5;
}

@media (max-width: 1024px) {
  .main-content {
    margin-left: 240px;
  }
  
  body.sidebar-collapsed .main-content {
    margin-left: 60px;
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
  
  .campus-badge {
    margin-left: 0;
    margin-top: 5px;
  }
  
  .add-btn, .back-btn {
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
  
  .modal-content {
    padding: 25px 20px;
    max-width: 95%;
  }
  
  .campus-info-bar {
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
  
  .stats-container {
    grid-template-columns: 1fr;
  }
}

.select2-container--default .select2-selection--single {
  height: 45px;
  border: 2px solid #e0e0e0;
  border-radius: 8px;
  padding: 8px 12px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
  line-height: 25px;
  color: var(--hormuud-dark);
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
  height: 43px;
  right: 10px;
}

.select2-dropdown {
  border: 2px solid var(--hormuud-green);
  border-radius: 8px;
  box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
}

.select2-results__option--highlighted[aria-selected] {
  background-color: var(--hormuud-green) !important;
}

.flatpickr-calendar {
  border-radius: 12px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
  border: none;
}

.flatpickr-day.selected {
  background: var(--hormuud-green) !important;
  border-color: var(--hormuud-green) !important;
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1>
      <i class="fas fa-calendar-alt"></i> Room Allocation Management
      
    </h1>
    <div style="display: flex; gap: 15px;">        
      <button class="add-btn" onclick="openModal('addAllocationModal')">
        <i class="fa-solid fa-plus"></i> New Allocation
      </button>
    </div>
  </div>  
  <!-- FILTERS -->
  <div class="filters-container">
    <div class="filter-header">
      <h3><i class="fas fa-filter"></i> Filter Allocations - <?= htmlspecialchars($campus_name) ?></h3>
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
                 placeholder="Room, term, department..."
                 value="<?= htmlspecialchars($search) ?>">
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
                <?= htmlspecialchars($faculty['faculty_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      
      <div class="filter-group">
        <label for="term">Academic Term</label>
        <div style="position:relative;">
          <i class="fas fa-calendar filter-icon"></i>
          <select id="term" name="term" class="filter-input">
            <option value="">All Terms</option>
            <?php foreach($academic_terms as $term): ?>
              <option value="<?= $term['academic_term_id'] ?>" 
                <?= $term_filter == $term['academic_term_id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($term['term_name'] . ' - ' . ($term['year_name'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
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
            <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
          </select>
        </div>
      </div>
      
      <div class="filter-group">
        <label for="date_from">From Date</label>
        <div style="position:relative;">
          <i class="fas fa-calendar-alt filter-icon"></i>
          <input type="date" id="date_from" name="date_from" class="filter-input" value="<?= $date_from ?>">
        </div>
      </div>
      
      <div class="filter-group">
        <label for="date_to">To Date</label>
        <div style="position:relative;">
          <i class="fas fa-calendar-alt filter-icon"></i>
          <input type="date" id="date_to" name="date_to" class="filter-input" value="<?= $date_to ?>">
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
  
  <!-- ALLOCATIONS TABLE -->
  <div class="table-wrapper">
    <div class="table-header">
      <h3><i class="fas fa-list"></i> Allocations List - <?= htmlspecialchars($campus_name) ?></h3>
      <div class="results-count">
        <i class="fas fa-eye"></i> Showing <?= count($allocations) ?> of <?= $total_allocations ?>
      </div>
    </div>
    
    <div class="table-container">
      <table class="data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Room</th>
            <th>Faculty / Dept</th>
            <th>Type</th>
            <th>Department</th>
            <th>Term</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Students / Capacity</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if($allocations): ?>
            <?php foreach($allocations as $i => $a): ?>
            <?php 
              $current_date = date('Y-m-d');
              $is_current = ($a['start_date'] <= $current_date && ($a['end_date'] === null || $a['end_date'] >= $current_date));
              
              $capacity_percentage = ($a['room_capacity'] > 0) ? round(($a['student_count'] / $a['room_capacity']) * 100) : 0;
              if ($a['student_count'] > $a['room_capacity']) {
                $capacity_class = 'capacity-warning';
              } elseif ($capacity_percentage >= 90) {
                $capacity_class = 'capacity-full';
              } elseif ($capacity_percentage >= 70) {
                $capacity_class = 'capacity-normal';
              } else {
                $capacity_class = 'capacity-normal';
              }
              
              $available_seats = ($a['room_capacity'] - $a['student_count']);
            ?>
            <tr>
              <td><strong><?= $i + 1 ?></strong></td>
              <td>
                <span class="room-badge">
                  <i class="fas fa-door-closed"></i> <?= htmlspecialchars($a['building_name'] ?? '') ?> - <?= htmlspecialchars($a['room_name'] ?? '') ?>
                </span>
                <div class="room-code"><?= htmlspecialchars($a['room_code'] ?? '') ?></div>
              </td>
              <td>
                <div style="font-size: 12px; line-height: 1.6;">
                  <span style="color: var(--hormuud-green);">
                    <i class="fas fa-university"></i> <?= htmlspecialchars($a['faculty_name'] ?? 'N/A') ?>
                  </span><br>
                  <span style="color: var(--hormuud-gold);">
                    <i class="fas fa-building"></i> <?= htmlspecialchars($a['department_name'] ?? 'All University') ?>
                  </span>
                </div>
              </td>
              <td>
                <span class="allocation-type type-<?= $a['allocated_to'] ?>">
                  <?php if($a['allocated_to'] == 'Class'): ?>
                    <i class="fas fa-chalkboard-teacher"></i>
                  <?php elseif($a['allocated_to'] == 'Exam'): ?>
                    <i class="fas fa-file-alt"></i>
                  <?php elseif($a['allocated_to'] == 'Maintenance'): ?>
                    <i class="fas fa-tools"></i>
                  <?php endif; ?>
                  <?= $a['allocated_to'] ?>
                </span>
              </td>
              <td>
                <?= htmlspecialchars($a['department_name'] ?? '<span style="color: #999; font-style: italic;">All University</span>') ?>
              </td>
              <td>
                <strong><?= htmlspecialchars($a['term_name'] ?? 'N/A') ?></strong>
                <div style="font-size: 11px; color: #666;"><?= htmlspecialchars($a['academic_year'] ?? '') ?></div>
              </td>
              <td><?= date('d M Y', strtotime($a['start_date'])) ?></td>
              <td>
                <?= $a['end_date'] ? date('d M Y', strtotime($a['end_date'])) : '<span style="color: var(--hormuud-green); font-weight: 600;"><i class="fas fa-infinity"></i> Ongoing</span>' ?>
              </td>
              <td>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                  <span class="<?= $capacity_class ?>">
                    <i class="fas fa-users"></i> <?= $a['student_count'] ?: '0' ?> / <?= $a['room_capacity'] ?? 'N/A' ?>
                  </span>
                  <div style="font-size: 11px;">
                    <?php if($available_seats > 0): ?>
                      <span style="color: var(--hormuud-green);">
                        <i class="fas fa-check-circle"></i> <?= $available_seats ?> available
                      </span>
                    <?php elseif($available_seats == 0): ?>
                      <span style="color: var(--hormuud-orange); font-weight: bold;">
                        <i class="fas fa-exclamation-circle"></i> Full
                      </span>
                    <?php else: ?>
                      <span style="color: var(--hormuud-red); font-weight: bold;">
                        <i class="fas fa-exclamation-triangle"></i> Exceeds by <?= abs($available_seats) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td>
                <?php 
                if (isset($a['display_status'])): 
                    if ($a['display_status'] === 'current'): ?>
                        <span class="status-badge status-active"><i class="fas fa-check-circle"></i> Current</span>
                    <?php elseif ($a['display_status'] === 'upcoming'): ?>
                        <span class="status-badge status-active" style="background: rgba(255, 184, 28, 0.1); color: var(--hormuud-gold);">
                            <i class="fas fa-clock"></i> Upcoming
                        </span>
                    <?php elseif ($a['display_status'] === 'completed'): ?>
                        <span class="status-badge status-completed"><i class="fas fa-check-double"></i> Completed</span>
                    <?php elseif ($a['status'] === 'inactive'): ?>
                        <span class="status-badge status-inactive"><i class="fas fa-ban"></i> Cancelled</span>
                    <?php else: ?>
                        <span class="status-badge status-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span>
                    <?php endif; 
                else: ?>
                    <span class="status-badge status-<?= $a['status'] ?>"><?= ucfirst($a['status']) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <div class="action-btns">
                  <button class="action-btn view-btn" 
                          onclick="openViewAllocationModal(<?= $a['allocation_id'] ?>)"
                          title="View Details">
                    <i class="fa-solid fa-eye"></i>
                  </button>
                  <button class="action-btn edit-btn" 
                          onclick="openEditAllocationModal(
                            <?= $a['allocation_id'] ?>,
                            <?= $a['room_id'] ?>,
                            <?= $a['faculty_id'] ?? 'null' ?>,
                            <?= $a['department_id'] ?? 'null' ?>,
                            <?= $a['academic_term_id'] ?>,
                            '<?= $a['allocated_to'] ?>',
                            '<?= $a['start_date'] ?>',
                            '<?= $a['end_date'] ?>',
                            '<?= addslashes($a['remarks'] ?? '') ?>',
                            <?= $a['student_count'] ?? 0 ?>,
                            '<?= $a['status'] ?>',
                            <?= $a['room_capacity'] ?? 0 ?>
                          )"
                          title="Edit">
                    <i class="fa-solid fa-pen"></i>
                  </button>
                  <button class="action-btn del-btn" 
                          onclick="openDeleteAllocationModal(<?= $a['allocation_id'] ?>)" 
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
                  <i class="fas fa-calendar-times"></i>
                  <h3>No allocations found for <?= htmlspecialchars($campus_name) ?></h3>
                  <p>
                    <?php if(!empty($search) || !empty($faculty_filter) || !empty($room_filter) || !empty($term_filter) || !empty($status_filter) || !empty($date_from) || !empty($date_to)): ?>
                      Try adjusting your filters or <a href="javascript:void(0)" onclick="clearFilters()" style="color: var(--hormuud-green); font-weight: 600;">clear all filters</a>
                    <?php else: ?>
                      Create your first allocation using the <strong style="color: var(--hormuud-green);">New Allocation</strong> button above
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

<!-- ADD ALLOCATION MODAL -->
<div class="modal" id="addAllocationModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addAllocationModal')">&times;</span>
    <h2><i class="fas fa-plus-circle"></i> Create Room Allocation - <?= htmlspecialchars($campus_name) ?></h2>
    
    <form method="POST" id="addAllocationForm" onsubmit="return validateAllocationForm('add')">
      <input type="hidden" name="action" value="add_allocation">
      <input type="hidden" name="campus_id" value="<?= $campus_id ?>">
      <input type="hidden" name="room_id" id="add_room_id">
      
      <div class="form-grid">
        <div class="selection-group">
          <label><i class="fas fa-map-marker-alt"></i> Campus</label>
          <input type="text" class="form-control" value="<?= htmlspecialchars($campus_name) ?>" disabled readonly>
        </div>
        
        <div class="selection-group">
          <label><i class="fas fa-university"></i> Faculty *</label>
          <select id="add_faculty" name="faculty_id" class="form-control" required onchange="loadDepartments('add', this.value)">
            <option value="">-- Select Faculty --</option>
            <?php foreach($faculties as $f): ?>
              <option value="<?= $f['faculty_id'] ?>">
                <?= htmlspecialchars($f['faculty_name']) ?> (<?= htmlspecialchars($f['faculty_code']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="selection-group">
          <label><i class="fas fa-building"></i> Department *</label>
          <select id="add_department" name="department_id" class="form-control" required disabled onchange="loadRooms('add')">
            <option value="">-- Select Faculty First --</option>
          </select>
        </div>
        
        <div class="selection-group">
          <label><i class="fas fa-door-closed"></i> Select Room *</label>
          <select id="add_room" name="room_id" class="form-control" required disabled onchange="updateCapacityInfo('add'); validateStudentCount('add');">
            <option value="">-- Select Department First --</option>
          </select>
          <div id="add_capacity_info" style="margin-top: 10px; font-size: 13px;"></div>
        </div>
        
        <div class="form-group">
          <label>Academic Term *</label>
          <select id="add_term" name="academic_term_id" class="form-control" required>
            <option value="">-- Select Term --</option>
            <?php foreach($academic_terms as $term): ?>
              <option value="<?= $term['academic_term_id'] ?>">
                <?= htmlspecialchars($term['term_name'] . ' - ' . ($term['year_name'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="error-message" id="add_term_error"></div>
        </div>
        
        <div class="form-group">
          <label>Allocated For *</label>
          <select id="add_allocated_to" name="allocated_to" class="form-control" required>
            <option value="">-- Select Purpose --</option>
            <option value="Class">📚 Class Session</option>
            <option value="Exam">📝 Examination</option>
            <option value="Maintenance">🔧 Maintenance</option>
          </select>
          <div class="error-message" id="add_allocated_to_error"></div>
        </div>
        
        <div class="form-group">
          <label>Start Date *</label>
          <input type="date" id="add_start_date" name="start_date" class="form-control" required min="<?= date('Y-m-d') ?>">
          <div class="error-message" id="add_start_date_error"></div>
        </div>
        
        <div class="form-group">
          <label>End Date</label>
          <input type="date" id="add_end_date" name="end_date" class="form-control" min="<?= date('Y-m-d') ?>">
          <div class="error-message" id="add_end_date_error"></div>
        </div>
        
        <div class="form-group">
          <label>Student Count <span id="add_capacity_limit" style="font-size: 11px; color: #666;"></span></label>
          <input type="number" id="add_student_count" name="student_count" class="form-control" min="0" value="0" oninput="validateStudentCount('add')">
          <div class="error-message" id="add_student_count_error"></div>
        </div>
        
        <div class="form-group" style="grid-column: 1 / -1;">
          <label>Remarks / Notes</label>
          <textarea id="add_remarks" name="remarks" class="form-control" rows="3" placeholder="Additional information about this allocation..."></textarea>
        </div>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-save"></i> Create Allocation
      </button>
    </form>
  </div>
</div>

<!-- EDIT ALLOCATION MODAL -->
<div class="modal" id="editAllocationModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('editAllocationModal')">&times;</span>
    <h2><i class="fas fa-edit"></i> Edit Allocation - <?= htmlspecialchars($campus_name) ?></h2>
    
    <form method="POST" id="editAllocationForm" onsubmit="return validateAllocationForm('edit')">
      <input type="hidden" name="action" value="update_allocation">
      <input type="hidden" name="allocation_id" id="edit_allocation_id">
      <input type="hidden" name="campus_id" value="<?= $campus_id ?>">
      <input type="hidden" name="room_id" id="edit_room_id">
      
      <div class="form-grid">
        <div class="selection-group">
          <label><i class="fas fa-map-marker-alt"></i> Campus</label>
          <input type="text" class="form-control" value="<?= htmlspecialchars($campus_name) ?>" disabled readonly>
        </div>
        
        <div class="selection-group">
          <label><i class="fas fa-university"></i> Faculty *</label>
          <select id="edit_faculty" name="faculty_id" class="form-control" required onchange="loadDepartments('edit', this.value)">
            <option value="">-- Select Faculty --</option>
            <?php foreach($faculties as $f): ?>
              <option value="<?= $f['faculty_id'] ?>">
                <?= htmlspecialchars($f['faculty_name']) ?> (<?= htmlspecialchars($f['faculty_code']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="selection-group">
          <label><i class="fas fa-building"></i> Department *</label>
          <select id="edit_department" name="department_id" class="form-control" required disabled onchange="loadRooms('edit')">
            <option value="">-- Select Faculty First --</option>
          </select>
        </div>
        
        <div class="selection-group">
          <label><i class="fas fa-door-closed"></i> Select Room *</label>
          <select id="edit_room" name="room_id" class="form-control" required disabled onchange="updateCapacityInfo('edit'); validateStudentCount('edit');">
            <option value="">-- Select Department First --</option>
          </select>
          <div id="edit_capacity_info" style="margin-top: 10px; font-size: 13px;"></div>
        </div>
        
        <div class="form-group">
          <label>Academic Term *</label>
          <select id="edit_term" name="academic_term_id" class="form-control" required>
            <option value="">-- Select Term --</option>
            <?php foreach($academic_terms as $term): ?>
              <option value="<?= $term['academic_term_id'] ?>">
                <?= htmlspecialchars($term['term_name'] . ' - ' . ($term['year_name'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="error-message" id="edit_term_error"></div>
        </div>
        
        <div class="form-group">
          <label>Allocated For *</label>
          <select id="edit_allocated_to" name="allocated_to" class="form-control" required>
            <option value="">-- Select Purpose --</option>
            <option value="Class">📚 Class Session</option>
            <option value="Exam">📝 Examination</option>
            <option value="Maintenance">🔧 Maintenance</option>
          </select>
          <div class="error-message" id="edit_allocated_to_error"></div>
        </div>
        
        <div class="form-group">
          <label>Start Date *</label>
          <input type="date" id="edit_start_date" name="start_date" class="form-control" required>
          <div class="error-message" id="edit_start_date_error"></div>
        </div>
        
        <div class="form-group">
          <label>End Date</label>
          <input type="date" id="edit_end_date" name="end_date" class="form-control">
          <div class="error-message" id="edit_end_date_error"></div>
        </div>
        
        <div class="form-group">
          <label>Student Count <span id="edit_capacity_limit" style="font-size: 11px; color: #666;"></span></label>
          <input type="number" id="edit_student_count" name="student_count" class="form-control" min="0" value="0" oninput="validateStudentCount('edit')">
          <div class="error-message" id="edit_student_count_error"></div>
        </div>
        
        <div class="form-group">
          <label>Status *</label>
          <select id="edit_status" name="status" class="form-control" required>
            <option value="active">✅ Active</option>
            <option value="inactive">❌ Inactive / Cancelled</option>
            <option value="completed">✔️ Completed</option>
          </select>
          <div class="error-message" id="edit_status_error"></div>
        </div>
        
        <div class="form-group" style="grid-column: 1 / -1;">
          <label>Remarks / Notes</label>
          <textarea id="edit_remarks" name="remarks" class="form-control" rows="3"></textarea>
        </div>
      </div>
      
      <button class="submit-btn" type="submit">
        <i class="fas fa-sync-alt"></i> Update Allocation
      </button>
    </form>
  </div>
</div>

<!-- VIEW ALLOCATION MODAL -->
<div class="modal" id="viewAllocationModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('viewAllocationModal')">&times;</span>
    <h2><i class="fas fa-eye"></i> Allocation Details</h2>
    
    <div class="view-content" id="allocationViewContent">
      <div class="loading-spinner">
        <i class="fas fa-spinner fa-spin"></i>
        <p>Loading allocation details...</p>
      </div>
    </div>
    
    <div style="display: flex; justify-content: center; gap: 15px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
      <button class="add-btn" onclick="closeModal('viewAllocationModal')" style="background: var(--hormuud-blue);">
        <i class="fas fa-times"></i> Close
      </button>
    </div>
  </div>
</div>

<!-- DELETE ALLOCATION MODAL -->
<div class="modal" id="deleteAllocationModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('deleteAllocationModal')">&times;</span>
    <h2 style="color: var(--hormuud-red);"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h2>
    <form method="POST">
      <input type="hidden" name="action" value="delete_allocation">
      <input type="hidden" name="allocation_id" id="delete_allocation_id">
      
      <div style="text-align: center; margin: 30px 0;">
        <i class="fa-solid fa-exclamation-triangle" style="font-size: 48px; color: var(--hormuud-red); margin-bottom: 20px;"></i>
        <p style="font-size: 16px; color: var(--hormuud-dark); margin-bottom: 10px;">
          Are you sure you want to delete this allocation?
        </p>
        <p style="color: #666; font-size: 14px;">
          This action cannot be undone.
        </p>
      </div>
      
      <button class="submit-btn delete-btn" type="submit">
        <i class="fas fa-trash-alt"></i> Yes, Delete Allocation
      </button>
    </form>
  </div>
</div>

<!-- ALERT POPUP -->
<div id="popup" class="alert-popup <?= $type ?> <?= !empty($message)?'show':'' ?>">
  <span class="alert-icon"><?= $type==='success' ? '✓' : '✗' ?></span>
  <div class="alert-message"><?= $message ?></div>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
// Store data
let facultiesData = <?= json_encode($faculties) ?>;
let departmentsData = <?= json_encode($departments) ?>;
let campusId = '<?= $campus_id ?>';

// Document ready
$(document).ready(function() {
    $('#faculty, #room, #term, #status').select2({
        placeholder: "Select option",
        allowClear: true,
        width: '100%'
    });
    
    flatpickr("input[type=date]", {
        dateFormat: "Y-m-d",
        allowInput: true
    });
    
    $('#faculty, #room, #term, #status').on('change', function() {
        document.getElementById('filterForm').submit();
    });
    
    let searchTimer;
    document.getElementById('search').addEventListener('input', function(e) {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            if (e.target.value.length === 0 || e.target.value.length > 2) {
                document.getElementById('filterForm').submit();
            }
        }, 600);
    });
    
    window.addEventListener('click', function(event) {
        if (event.target.classList.contains('modal')) {
            closeModal(event.target.id);
        }
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.show').forEach(modal => {
                closeModal(modal.id);
            });
        }
    });
    
    const alert = document.getElementById('popup');
    if (alert && alert.classList.contains('show')) {
        setTimeout(() => {
            alert.classList.remove('show');
        }, 3500);
    }
    
    const addStartDate = document.getElementById('add_start_date');
    const addEndDate = document.getElementById('add_end_date');
    if (addStartDate && addEndDate) {
        addStartDate.addEventListener('change', function() {
            addEndDate.min = this.value;
        });
    }
    
    const editStartDate = document.getElementById('edit_start_date');
    const editEndDate = document.getElementById('edit_end_date');
    if (editStartDate && editEndDate) {
        editStartDate.addEventListener('change', function() {
            editEndDate.min = this.value;
        });
    }
    
    ['add', 'edit'].forEach(prefix => {
        const form = document.getElementById(prefix + 'AllocationForm');
        if (form) {
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('change', function() {
                    this.classList.remove('error');
                    const errorElement = document.getElementById(prefix + '_' + this.id.split('_').pop() + '_error');
                    if (errorElement) {
                        errorElement.style.display = 'none';
                    }
                });
            });
        }
    });
});

function updateCapacityInfo(prefix) {
    const roomSelect = document.getElementById(prefix + '_room');
    const capacityInfo = document.getElementById(prefix + '_capacity_info');
    const capacityLimit = document.getElementById(prefix + '_capacity_limit');
    
    if (!roomSelect || !roomSelect.value) {
        if (capacityInfo) capacityInfo.innerHTML = '';
        if (capacityLimit) capacityLimit.innerHTML = '';
        return;
    }
    
    const selectedOption = roomSelect.options[roomSelect.selectedIndex];
    const optionText = selectedOption.textContent;
    const capacityMatch = optionText.match(/Capacity: (\d+)/);
    
    if (capacityMatch) {
        const capacity = parseInt(capacityMatch[1]);
        
        if (capacityInfo) {
            capacityInfo.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f0f8f0; border-radius: 8px; border-left: 4px solid var(--hormuud-green);">
                    <i class="fas fa-door-open" style="color: var(--hormuud-green); font-size: 18px;"></i>
                    <div>
                        <span style="font-weight: 600; color: var(--hormuud-dark);">Room Capacity:</span>
                        <span style="font-size: 16px; font-weight: 700; color: var(--hormuud-green); margin-left: 5px;">${capacity} students</span>
                    </div>
                </div>
            `;
        }
        
        if (capacityLimit) {
            capacityLimit.innerHTML = `(Max: ${capacity})`;
        }
    }
}

function validateStudentCount(prefix) {
    const roomSelect = document.getElementById(prefix + '_room');
    const studentCountInput = document.getElementById(prefix + '_student_count');
    const errorElement = document.getElementById(prefix + '_student_count_error');
    
    if (!roomSelect || !studentCountInput) {
        return true;
    }
    
    if (!roomSelect.value) {
        if (errorElement) {
            errorElement.style.display = 'none';
        }
        return true;
    }
    
    const selectedOption = roomSelect.options[roomSelect.selectedIndex];
    const optionText = selectedOption.textContent;
    const capacityMatch = optionText.match(/Capacity: (\d+)/);
    
    if (capacityMatch) {
        const capacity = parseInt(capacityMatch[1]);
        const studentCount = parseInt(studentCountInput.value) || 0;
        
        if (studentCount > capacity) {
            studentCountInput.classList.add('error');
            if (errorElement) {
                errorElement.textContent = `❌ Student count (${studentCount}) cannot exceed room capacity (${capacity})`;
                errorElement.style.display = 'block';
            }
            return false;
        } else {
            studentCountInput.classList.remove('error');
            if (errorElement) {
                errorElement.style.display = 'none';
            }
        }
    }
    
    return true;
}

function loadDepartments(prefix, facultyId) {
    const deptSelect = document.getElementById(prefix + '_department');
    const roomSelect = document.getElementById(prefix + '_room');
    const studentCountInput = document.getElementById(prefix + '_student_count');
    const capacityInfo = document.getElementById(prefix + '_capacity_info');
    
    if (!facultyId) {
        deptSelect.innerHTML = '<option value="">-- Select Faculty First --</option>';
        deptSelect.disabled = true;
        roomSelect.innerHTML = '<option value="">-- Select Department First --</option>';
        roomSelect.disabled = true;
        if (capacityInfo) capacityInfo.innerHTML = '';
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    roomSelect.innerHTML = '<option value="">-- Select Department First --</option>';
    roomSelect.disabled = true;
    
    if (studentCountInput) {
        studentCountInput.value = '';
    }
    if (capacityInfo) capacityInfo.innerHTML = '';
    
    fetch(`?ajax=get_departments&faculty_id=${facultyId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                deptSelect.innerHTML = '<option value="">-- Select Department --</option>';
                data.departments.forEach(d => {
                    const option = document.createElement('option');
                    option.value = d.department_id;
                    option.textContent = `${d.department_name} (${d.department_code})`;
                    deptSelect.appendChild(option);
                });
                deptSelect.disabled = false;
            } else {
                deptSelect.innerHTML = '<option value="">Error loading departments</option>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            deptSelect.innerHTML = '<option value="">Error loading departments</option>';
        });
}

function loadRooms(prefix) {
    const facultySelect = document.getElementById(prefix + '_faculty');
    const deptSelect = document.getElementById(prefix + '_department');
    const roomSelect = document.getElementById(prefix + '_room');
    const roomIdHidden = document.getElementById(prefix + '_room_id');
    const studentCountInput = document.getElementById(prefix + '_student_count');
    const capacityInfo = document.getElementById(prefix + '_capacity_info');
    
    const facultyId = facultySelect.value;
    const departmentId = deptSelect.value;
    
    if (!facultyId || !departmentId) {
        roomSelect.innerHTML = '<option value="">-- Select Department First --</option>';
        roomSelect.disabled = true;
        if (capacityInfo) capacityInfo.innerHTML = '';
        return;
    }
    
    roomSelect.innerHTML = '<option value="">Loading rooms...</option>';
    roomSelect.disabled = true;
    
    if (studentCountInput) {
        studentCountInput.value = '';
    }
    if (capacityInfo) capacityInfo.innerHTML = '';
    
    fetch(`?ajax=get_rooms&faculty_id=${facultyId}&department_id=${departmentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                roomSelect.innerHTML = '<option value="">-- Select Room --</option>';
                
                if (data.rooms.length === 0) {
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = 'No rooms available';
                    option.disabled = true;
                    roomSelect.appendChild(option);
                } else {
                    data.rooms.forEach(room => {
                        const option = document.createElement('option');
                        option.value = room.room_id;
                        option.textContent = `${room.building_name} - ${room.room_name} (${room.room_code}) - Capacity: ${room.capacity}`;
                        option.setAttribute('data-capacity', room.capacity);
                        if (roomIdHidden && room.room_id == roomIdHidden.value) {
                            option.selected = true;
                        }
                        roomSelect.appendChild(option);
                    });
                }
                
                roomSelect.disabled = false;
                
                updateCapacityInfo(prefix);
                if (studentCountInput && studentCountInput.value) {
                    validateStudentCount(prefix);
                }
            } else {
                roomSelect.innerHTML = '<option value="">Error loading rooms</option>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            roomSelect.innerHTML = '<option value="">Error loading rooms</option>';
        });
}

function validateAllocationForm(prefix) {
    let isValid = true;
    
    const facultySelect = document.getElementById(prefix + '_faculty');
    const deptSelect = document.getElementById(prefix + '_department');
    const roomSelect = document.getElementById(prefix + '_room');
    const termSelect = document.getElementById(prefix + '_term');
    const allocatedSelect = document.getElementById(prefix + '_allocated_to');
    const startDate = document.getElementById(prefix + '_start_date');
    
    if (!facultySelect.value) {
        alert('❌ Please select a faculty');
        isValid = false;
    }
    
    if (!deptSelect.value) {
        alert('❌ Please select a department');
        isValid = false;
    }
    
    if (!roomSelect.value) {
        alert('❌ Please select a room');
        isValid = false;
    }
    
    if (!termSelect.value) {
        termSelect.classList.add('error');
        document.getElementById(prefix + '_term_error').textContent = '❌ Please select academic term';
        document.getElementById(prefix + '_term_error').style.display = 'block';
        isValid = false;
    } else {
        termSelect.classList.remove('error');
        document.getElementById(prefix + '_term_error').style.display = 'none';
    }
    
    if (!allocatedSelect.value) {
        allocatedSelect.classList.add('error');
        document.getElementById(prefix + '_allocated_to_error').textContent = '❌ Please select allocation purpose';
        document.getElementById(prefix + '_allocated_to_error').style.display = 'block';
        isValid = false;
    } else {
        allocatedSelect.classList.remove('error');
        document.getElementById(prefix + '_allocated_to_error').style.display = 'none';
    }
    
    if (!startDate.value) {
        startDate.classList.add('error');
        document.getElementById(prefix + '_start_date_error').textContent = '❌ Please select start date';
        document.getElementById(prefix + '_start_date_error').style.display = 'block';
        isValid = false;
    } else {
        startDate.classList.remove('error');
        document.getElementById(prefix + '_start_date_error').style.display = 'none';
    }
    
    const endDate = document.getElementById(prefix + '_end_date');
    if (endDate.value && startDate.value) {
        if (new Date(endDate.value) < new Date(startDate.value)) {
            endDate.classList.add('error');
            document.getElementById(prefix + '_end_date_error').textContent = '❌ End date must be after start date';
            document.getElementById(prefix + '_end_date_error').style.display = 'block';
            isValid = false;
        } else {
            endDate.classList.remove('error');
            document.getElementById(prefix + '_end_date_error').style.display = 'none';
        }
    }
    
    if (!validateStudentCount(prefix)) {
        isValid = false;
    }
    
    return isValid;
}

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
        
        if (id === 'addAllocationModal') {
            document.getElementById('addAllocationForm').reset();
            document.getElementById('add_department').innerHTML = '<option value="">-- Select Faculty First --</option>';
            document.getElementById('add_department').disabled = true;
            document.getElementById('add_room').innerHTML = '<option value="">-- Select Department First --</option>';
            document.getElementById('add_room').disabled = true;
            document.getElementById('add_capacity_info').innerHTML = '';
        }
    }
}

function clearFilters() {
    window.location.href = window.location.pathname;
}

function openEditAllocationModal(id, roomId, facultyId, deptId, termId, allocatedTo, startDate, endDate, remarks, studentCount, status, roomCapacity) {
    openModal('editAllocationModal');
    
    console.log('=== EDIT MODAL OPENED ===');
    console.log('Status received:', status);
    console.log('Student count received:', studentCount);
    
    document.getElementById('edit_allocation_id').value = id;
    document.getElementById('edit_room_id').value = roomId;
    document.getElementById('edit_term').value = termId;
    document.getElementById('edit_allocated_to').value = allocatedTo;
    document.getElementById('edit_start_date').value = startDate;
    document.getElementById('edit_end_date').value = endDate;
    document.getElementById('edit_student_count').value = studentCount || 0;
    document.getElementById('edit_remarks').value = remarks;
    
    const statusSelect = document.getElementById('edit_status');
    
    if (status && status !== '' && status !== 'null' && status !== 'undefined') {
        statusSelect.value = status;
        console.log('✅ Status set to:', statusSelect.value);
    } else {
        statusSelect.value = 'active';
        console.log('⚠️ Status was invalid, set to active');
    }
    
    console.log('Final student count value:', document.getElementById('edit_student_count').value);
    
    if (facultyId && facultyId !== 'null') {
        document.getElementById('edit_faculty').value = facultyId;
        
        setTimeout(() => {
            loadDepartments('edit', facultyId);
            
            setTimeout(() => {
                if (deptId && deptId !== 'null') {
                    document.getElementById('edit_department').value = deptId;
                    
                    setTimeout(() => {
                        loadRooms('edit');
                        
                        setTimeout(() => {
                            if (roomId && roomId !== 'null') {
                                document.getElementById('edit_room').value = roomId;
                            }
                            updateCapacityInfo('edit');
                            
                            setTimeout(() => {
                                validateStudentCount('edit');
                            }, 100);
                        }, 200);
                    }, 200);
                }
            }, 200);
        }, 200);
    }
}

function openViewAllocationModal(id) {
    openModal('viewAllocationModal');
    
    document.getElementById('allocationViewContent').innerHTML = `
        <div class="loading-spinner">
            <i class="fas fa-spinner fa-spin"></i>
            <p>Loading allocation details...</p>
        </div>
    `;
    
    fetch(`?ajax=get_allocation_details&allocation_id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success' && data.allocation) {
                const a = data.allocation;
                const currentDate = new Date().toISOString().split('T')[0];
                const isCurrent = a.start_date <= currentDate && (!a.end_date || a.end_date >= currentDate);
                
                let capacityStatus = '';
                let capacityColor = '';
                
                if (a.student_count > a.room_capacity) {
                    capacityStatus = '⚠️ Exceeds Capacity';
                    capacityColor = 'var(--hormuud-red)';
                } else if (a.student_count == a.room_capacity) {
                    capacityStatus = '✅ Full';
                    capacityColor = 'var(--hormuud-orange)';
                } else {
                    capacityStatus = '🟢 Available';
                    capacityColor = 'var(--hormuud-green)';
                }
                
                let html = `
                    <div class="view-details">
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-map-marker-alt"></i> Campus</div>
                            <div class="detail-value">${a.campus_name || 'Not specified'}</div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-university"></i> Faculty</div>
                            <div class="detail-value">${a.faculty_name || 'Not specified'}</div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-building"></i> Department</div>
                            <div class="detail-value">${a.department_name || 'All University'}</div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-door-closed"></i> Room</div>
                            <div class="detail-value">
                                <strong>${a.building_name || ''} - ${a.room_name || ''}</strong>
                                <div style="font-size: 12px; color: #666; margin-top: 5px;">
                                    Code: ${a.room_code || ''} | Capacity: ${a.room_capacity || 'N/A'} seats
                                </div>
                            </div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-tag"></i> Allocation Type</div>
                            <div class="detail-value">
                                <span class="allocation-type type-${a.allocated_to}">${a.allocated_to}</span>
                            </div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-calendar"></i> Academic Term</div>
                            <div class="detail-value">
                                <strong>${a.term_name || 'N/A'}</strong>
                                <div style="font-size: 12px; color: #666;">${a.academic_year || ''}</div>
                            </div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-calendar-alt"></i> Start Date</div>
                            <div class="detail-value">${formatDate(a.start_date)}</div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-calendar-alt"></i> End Date</div>
                            <div class="detail-value">${a.end_date ? formatDate(a.end_date) : 'Ongoing'}</div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-users"></i> Student Count</div>
                            <div class="detail-value">
                                <span style="font-size: 18px; font-weight: 700; color: ${capacityColor};">${a.student_count || 0}</span> / ${a.room_capacity || 0}
                                <div style="font-size: 12px; margin-top: 5px; color: ${capacityColor};">
                                    <i class="fas fa-${a.student_count > a.room_capacity ? 'exclamation-triangle' : 'check-circle'}"></i> 
                                    ${capacityStatus}
                                </div>
                            </div>
                        </div>
                        
                        <div class="detail-item">
                            <div class="detail-label"><i class="fas fa-circle"></i> Status</div>
                            <div class="detail-value">
                                ${isCurrent ? '<span class="status-badge status-active"><i class="fas fa-check-circle"></i> Current</span>' : 
                                  a.status === 'active' && a.start_date > currentDate ? '<span class="status-badge status-active" style="background: rgba(255, 184, 28, 0.1); color: var(--hormuud-gold);"><i class="fas fa-clock"></i> Upcoming</span>' : 
                                  a.status === 'active' && a.end_date < currentDate ? '<span class="status-badge status-completed"><i class="fas fa-check-double"></i> Completed</span>' : 
                                  '<span class="status-badge status-' + a.status + '">' + (a.status === 'inactive' ? '<i class="fas fa-ban"></i> Cancelled' : a.status) + '</span>'}
                            </div>
                        </div>
                        
                        <div class="detail-item" style="grid-column: 1 / -1;">
                            <div class="detail-label"><i class="fas fa-align-left"></i> Remarks</div>
                            <div class="detail-value">${a.remarks || 'No remarks provided'}</div>
                        </div>
                    </div>
                `;
                
                document.getElementById('allocationViewContent').innerHTML = html;
            } else {
                document.getElementById('allocationViewContent').innerHTML = `
                    <div style="text-align: center; padding: 40px; color: var(--hormuud-red);">
                        <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i>
                        <p style="font-size: 16px;">Error loading allocation details.</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('allocationViewContent').innerHTML = `
                <div style="text-align: center; padding: 40px; color: var(--hormuud-red);">
                    <i class="fas fa-exclamation-triangle" style="font-size: 48px; margin-bottom: 15px;"></i>
                    <p style="font-size: 16px;">Error loading allocation details.</p>
                </div>
            `;
        });
}

function openDeleteAllocationModal(id) {
    openModal('deleteAllocationModal');
    document.getElementById('delete_allocation_id').value = id;
}

function formatDate(dateString) {
    if (!dateString) return '';
    const options = { year: 'numeric', month: 'long', day: 'numeric' };
    return new Date(dateString).toLocaleDateString(undefined, options);
}
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>