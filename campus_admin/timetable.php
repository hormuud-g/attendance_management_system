<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control
$role = strtolower($_SESSION['user']['role'] ?? '');
if (!in_array($role, ['super_admin', 'campus_admin'])) {
    header("Location: ../login.php");
    exit;
}

$user = $_SESSION['user'] ?? [];
$user_campus_id = $user['linked_id'] ?? null;

$message = "";
$type = "";

/* ===========================================
   AJAX HANDLERS FOR HIERARCHY
=========================================== */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // GET FACULTIES BY CAMPUS
    if ($_GET['ajax'] == 'get_faculties') {
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Campus ID required']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT DISTINCT f.faculty_id, f.faculty_name 
            FROM faculties f
            JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
            WHERE fc.campus_id = ?
            AND f.status = 'active'
            ORDER BY f.faculty_name
        ");
        $stmt->execute([$campus_id]);
        $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($faculties) {
            echo json_encode(['status' => 'success', 'faculties' => $faculties]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No active faculties found for this campus']);
        }
        exit;
    }
    
    // GET DEPARTMENTS BY FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_departments') {
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Faculty ID and Campus ID required']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT department_id, department_name 
            FROM departments 
            WHERE faculty_id = ? 
            AND campus_id = ?
            AND status = 'active'
            ORDER BY department_name
        ");
        $stmt->execute([$faculty_id, $campus_id]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($departments) {
            echo json_encode(['status' => 'success', 'departments' => $departments]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No active departments found']);
        }
        exit;
    }
    
    // GET PROGRAMS BY DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_programs') {
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$department_id || !$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Department, Faculty and Campus ID required']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT program_id, program_name, program_code 
            FROM programs 
            WHERE department_id = ? 
            AND faculty_id = ?
            AND campus_id = ?
            AND status = 'active'
            ORDER BY program_name
        ");
        $stmt->execute([$department_id, $faculty_id, $campus_id]);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($programs) {
            echo json_encode(['status' => 'success', 'programs' => $programs]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No active programs found for this department']);
        }
        exit;
    }
    
    // GET CLASSES BY PROGRAM, DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_classes') {
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$program_id || !$department_id || !$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'All IDs required']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT class_id, class_name 
            FROM classes 
            WHERE program_id = ? 
            AND department_id = ?
            AND faculty_id = ?
            AND campus_id = ?
            AND status = 'Active'
            ORDER BY class_name
        ");
        $stmt->execute([$program_id, $department_id, $faculty_id, $campus_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($classes) {
            echo json_encode(['status' => 'success', 'classes' => $classes]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No active classes found for this program']);
        }
        exit;
    }
    
    // GET SUBJECTS BY CLASS, PROGRAM, DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_subjects') {
        $class_id = $_GET['class_id'] ?? 0;
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$class_id || !$program_id || !$department_id || !$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'All IDs required']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT subject_id, subject_name, subject_code 
            FROM subject 
            WHERE class_id = ? 
            AND program_id = ?
            AND department_id = ?
            AND faculty_id = ?
            AND campus_id = ?
            AND status = 'active'
            ORDER BY subject_name
        ");
        $stmt->execute([$class_id, $program_id, $department_id, $faculty_id, $campus_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($subjects) {
            echo json_encode(['status' => 'success', 'subjects' => $subjects]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No active subjects found for this class']);
        }
        exit;
    }
    
    // GET TEACHERS (ALL ACTIVE TEACHERS)
    if ($_GET['ajax'] == 'get_teachers') {
        $stmt = $pdo->prepare("
            SELECT teacher_id, teacher_name 
            FROM teachers 
            WHERE status = 'active'
            ORDER BY teacher_name
        ");
        $stmt->execute();
        $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($teachers) {
            echo json_encode(['status' => 'success', 'teachers' => $teachers]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No active teachers found']);
        }
        exit;
    }
    
    // GET ROOMS BY CAMPUS, FACULTY, AND DEPARTMENT
    if ($_GET['ajax'] == 'get_rooms') {
        $campus_id = $_GET['campus_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? null;
        $department_id = $_GET['department_id'] ?? null;
        
        if (!$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Campus ID required']);
            exit;
        }
        
        // Start building query
        $sql = "SELECT room_id, room_name, room_code, room_type, capacity 
                FROM rooms 
                WHERE campus_id = ? 
                AND status = 'available'";
        
        $params = [$campus_id];
        
        // Add faculty filter if provided
        if ($faculty_id) {
            $sql .= " AND (faculty_id = ? OR faculty_id IS NULL)";
            $params[] = $faculty_id;
        }
        
        // Add department filter if provided
        if ($department_id) {
            $sql .= " AND (department_id = ? OR department_id IS NULL)";
            $params[] = $department_id;
        }
        
        $sql .= " ORDER BY room_name";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if ($rooms) {
            echo json_encode(['status' => 'success', 'rooms' => $rooms]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No rooms found for this campus']);
        }
        exit;
    }
}

/* ===========================================
   CRUD OPERATIONS
=========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        function validateRequired($fields) {
            foreach ($fields as $label => $value) {
                if (empty($value)) throw new Exception("⚠️ '$label' field is required.");
            }
        }

        if (in_array($action, ['add', 'update'])) {
            $id = $_POST['timetable_id'] ?? null;
            $campus_id = $_POST['campus_id'] ?? '';
            $faculty_id = $_POST['faculty_id'] ?? '';
            $department_id = $_POST['department_id'] ?? '';
            $program_id = $_POST['program_id'] ?? '';
            $class_id = $_POST['class_id'] ?? '';
            $subject_id = $_POST['subject_id'] ?? '';
            $teacher_id = $_POST['teacher_id'] ?? null;
            $room_id = $_POST['room_id'] ?? null;
            $term_id = $_POST['academic_term_id'] ?? '';
            $day = $_POST['day_of_week'] ?? '';
            $start = $_POST['start_time'] ?? '';
            $end = $_POST['end_time'] ?? '';
            $status = $_POST['status'] ?? 'active';

            validateRequired([
                "Campus" => $campus_id,
                "Faculty" => $faculty_id,
                "Department" => $department_id,
                "Program" => $program_id,
                "Class" => $class_id,
                "Subject" => $subject_id,
                "Academic Term" => $term_id,
                "Day" => $day,
                "Start Time" => $start,
                "End Time" => $end
            ]);

            if ($end <= $start) throw new Exception("⛔ End time must be later than start time.");

            // ✅ Conflict Validation (Class / Teacher / Room)
            $conflictQuery = "
                SELECT timetable_id FROM timetable
                WHERE academic_term_id = ?
                AND day_of_week = ?
                AND timetable_id != COALESCE(?, 0)
                AND (
                    (class_id = ? AND campus_id = ? AND faculty_id = ? AND department_id = ? AND program_id = ?)
                    OR (teacher_id IS NOT NULL AND teacher_id = ?)
                    OR (room_id IS NOT NULL AND room_id = ?)
                )
                AND (
                    (? < end_time AND ? > start_time)
                )
            ";

            $stmt = $pdo->prepare($conflictQuery);
            $stmt->execute([
                $term_id, $day, $id,
                $class_id, $campus_id, $faculty_id, $department_id, $program_id,
                $teacher_id, $room_id,
                $start, $end
            ]);

            if ($stmt->fetch()) throw new Exception("❌ Time conflict! Class, teacher, or room already scheduled in that time.");

            // ✅ Insert or Update
            if ($action === 'add') {
                $pdo->prepare("
                    INSERT INTO timetable 
                    (campus_id, faculty_id, department_id, program_id, class_id, subject_id, 
                     teacher_id, room_id, academic_term_id, day_of_week, start_time, end_time, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $campus_id, $faculty_id, $department_id, $program_id, $class_id, $subject_id,
                    $teacher_id, $room_id, $term_id, $day, $start, $end, $status
                ]);
                $message = "✅ Timetable entry added successfully!";
            } else {
                $pdo->prepare("
                    UPDATE timetable 
                    SET campus_id=?, faculty_id=?, department_id=?, program_id=?, class_id=?, 
                        subject_id=?, teacher_id=?, room_id=?, academic_term_id=?, 
                        day_of_week=?, start_time=?, end_time=?, status=?, updated_at=NOW()
                    WHERE timetable_id=?
                ")->execute([
                    $campus_id, $faculty_id, $department_id, $program_id, $class_id, $subject_id,
                    $teacher_id, $room_id, $term_id, $day, $start, $end, $status, $id
                ]);
                $message = "✅ Timetable updated successfully!";
            }

            $type = "success";
        }

        elseif ($action === 'delete') {
            $id = $_POST['timetable_id'] ?? null;
            if (!$id) throw new Exception("Missing timetable ID.");
            $pdo->prepare("DELETE FROM timetable WHERE timetable_id=?")->execute([$id]);
            $message = "✅ Timetable deleted successfully!";
            $type = "success";
        }

    } catch (Exception $e) {
        $message = $e->getMessage();
        $type = "error";
    }
}

/* ===========================================
   FETCH DATA FOR DROPDOWNS
=========================================== */
// Get filter values from GET
$selectedCampus = $_GET['campus_id'] ?? ($role === 'campus_admin' ? $user_campus_id : null);
$selectedFaculty = $_GET['faculty_id'] ?? null;
$selectedDepartment = $_GET['department_id'] ?? null;
$selectedProgram = $_GET['program_id'] ?? null;
$selectedClass = $_GET['class_id'] ?? null;
$selectedTerm = $_GET['academic_term_id'] ?? null;

/* ===========================================
   FETCH STATIC DATA
=========================================== */
// Campuses (ACTIVE ONLY)
if ($role === 'super_admin') {
    $campuses = $pdo->query("SELECT campus_id, campus_name FROM campus WHERE status = 'active' ORDER BY campus_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $campuses = $pdo->prepare("SELECT campus_id, campus_name FROM campus WHERE campus_id = ? AND status = 'active'");
    $campuses->execute([$user_campus_id]);
    $campuses = $campuses->fetchAll(PDO::FETCH_ASSOC);
}

// Terms
$terms = $pdo->query("SELECT * FROM academic_term ORDER BY term_name DESC")->fetchAll(PDO::FETCH_ASSOC);

// For main filter dropdowns - Hierarchical loading (ACTIVE ONLY)
$filterFaculties = [];
if ($selectedCampus) {
    $filterFaculties = $pdo->prepare("
        SELECT DISTINCT f.faculty_id, f.faculty_name
        FROM faculties f
        JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
        WHERE fc.campus_id = ?
        AND f.status = 'active'
        ORDER BY f.faculty_name ASC
    ");
    $filterFaculties->execute([$selectedCampus]);
    $filterFaculties = $filterFaculties->fetchAll(PDO::FETCH_ASSOC);
}

$filterDepartments = [];
if ($selectedFaculty && $selectedCampus) {
    $filterDepartments = $pdo->prepare("
        SELECT department_id, department_name
        FROM departments
        WHERE faculty_id = ? 
        AND campus_id = ?
        AND status = 'active'
        ORDER BY department_name ASC
    ");
    $filterDepartments->execute([$selectedFaculty, $selectedCampus]);
    $filterDepartments = $filterDepartments->fetchAll(PDO::FETCH_ASSOC);
}

$filterPrograms = [];
if ($selectedDepartment && $selectedFaculty && $selectedCampus) {
    $filterPrograms = $pdo->prepare("
        SELECT program_id, program_name, program_code
        FROM programs
        WHERE department_id = ? 
        AND faculty_id = ?
        AND campus_id = ?
        AND status = 'active'
        ORDER BY program_name ASC
    ");
    $filterPrograms->execute([$selectedDepartment, $selectedFaculty, $selectedCampus]);
    $filterPrograms = $filterPrograms->fetchAll(PDO::FETCH_ASSOC);
}

$filterClasses = [];
if ($selectedProgram && $selectedDepartment && $selectedFaculty && $selectedCampus) {
    $filterClasses = $pdo->prepare("
        SELECT class_id, class_name
        FROM classes
        WHERE program_id = ? 
        AND department_id = ?
        AND faculty_id = ?
        AND campus_id = ?
        AND status = 'Active'
        ORDER BY class_name ASC
    ");
    $filterClasses->execute([$selectedProgram, $selectedDepartment, $selectedFaculty, $selectedCampus]);
    $filterClasses = $filterClasses->fetchAll(PDO::FETCH_ASSOC);
}

// Other data (for modals - will be loaded dynamically via AJAX)
$allTerms = $pdo->query("SELECT * FROM academic_term ORDER BY term_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$allDays = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

/* ===========================================
   FETCH TIMETABLE DATA WITH HIERARCHY
=========================================== */
// Build WHERE clause based on filters
$whereConditions = [];
$params = [];

if ($selectedCampus) {
    $whereConditions[] = "t.campus_id = ?";
    $params[] = $selectedCampus;
}

if ($selectedFaculty) {
    $whereConditions[] = "t.faculty_id = ?";
    $params[] = $selectedFaculty;
}

if ($selectedDepartment) {
    $whereConditions[] = "t.department_id = ?";
    $params[] = $selectedDepartment;
}

if ($selectedProgram) {
    $whereConditions[] = "t.program_id = ?";
    $params[] = $selectedProgram;
}

if ($selectedClass) {
    $whereConditions[] = "t.class_id = ?";
    $params[] = $selectedClass;
}

if ($selectedTerm) {
    $whereConditions[] = "t.academic_term_id = ?";
    $params[] = $selectedTerm;
}

$whereClause = $whereConditions ? "WHERE " . implode(" AND ", $whereConditions) : "";

$timetablesQuery = "
    SELECT t.*, 
           c.class_name, 
           ca.campus_name,
           f.faculty_name, 
           d.department_name, 
           p.program_name,
           subj.subject_name, subj.subject_code,
           r.room_name, r.room_code, r.room_type,
           tr.teacher_name,
           at.term_name
    FROM timetable t
    JOIN classes c ON t.class_id = c.class_id 
      AND t.campus_id = c.campus_id 
      AND t.faculty_id = c.faculty_id 
      AND t.department_id = c.department_id 
      AND t.program_id = c.program_id
    JOIN campus ca ON t.campus_id = ca.campus_id AND ca.status = 'active'
    JOIN faculties f ON t.faculty_id = f.faculty_id AND f.status = 'active'
    JOIN departments d ON t.department_id = d.department_id AND d.status = 'active'
    JOIN programs p ON t.program_id = p.program_id AND p.status = 'active'
    JOIN subject subj ON t.subject_id = subj.subject_id AND subj.status = 'active'
    LEFT JOIN teachers tr ON t.teacher_id = tr.teacher_id AND tr.status = 'active'
    LEFT JOIN rooms r ON t.room_id = r.room_id AND r.status = 'available'
    JOIN academic_term at ON t.academic_term_id = at.academic_term_id
    $whereClause
    ORDER BY 
        FIELD(t.day_of_week, 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'),
        t.start_time
";

if ($whereConditions) {
    $stmt = $pdo->prepare($timetablesQuery);
    $stmt->execute($params);
    $timetables = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $timetables = $pdo->query($timetablesQuery)->fetchAll(PDO::FETCH_ASSOC);
}

// Days order
$daysOrder = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// Group timetable by day for tabs
$timetableByDay = [];
foreach ($timetables as $t) {
    $day = $t['day_of_week'];
    if (!isset($timetableByDay[$day])) {
        $timetableByDay[$day] = [];
    }
    $timetableByDay[$day][] = $t;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Class Timetable | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --green: #00843D;
    --blue: #0072CE;
    --red: #C62828;
    --orange: #FF9800;
    --light-green: #00A651;
    --bg: #F5F9F7;
}
body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
    margin: 0;
    color: #333;
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

/* Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.page-header h1 {
    color: var(--blue);
    font-size: 24px;
    margin: 0;
    font-weight: 700;
}
.add-btn {
    background: var(--green);
    color: #fff;
    border: none;
    padding: 10px 18px;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    transition: .3s;
}
.add-btn:hover {
    background: var(--light-green);
}

/* Filter Box */
.filter-box {
    background: #fff;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    margin-bottom: 20px;
    border-top: 4px solid var(--blue);
}
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}
label {
    font-weight: 600;
    color: var(--blue);
    font-size: 13px;
    margin-bottom: 5px;
    display: block;
}
select {
    width: 100%;
    padding: 8px 10px;
    border: 1.5px solid #ccc;
    border-radius: 6px;
    font-size: 13.5px;
    background: #f9f9f9;
    transition: 0.2s;
}
select:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(0,114,206,0.12);
    outline: none;
}
.btn {
    border: none;
    padding: 10px 18px;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    transition: .3s;
}
.btn.blue {
    background: var(--blue);
    color: #fff;
}
.btn.blue:hover {
    background: #0056b3;
}
.btn:disabled {
    background: #ccc;
    cursor: not-allowed;
}

/* Timetable Container */
.timetable-container {
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    overflow: hidden;
}
.timetable-header {
    background: var(--blue);
    color: white;
    padding: 15px 20px;
    font-weight: 600;
    font-size: 18px;
}

/* Tabs */
.tabs {
    display: flex;
    background: #f0f7ff;
    border-bottom: 1px solid #ddd;
    overflow-x: auto;
}
.tab {
    padding: 12px 20px;
    background: transparent;
    border: none;
    font-weight: 600;
    color: #666;
    cursor: pointer;
    transition: all 0.3s;
    border-bottom: 3px solid transparent;
    white-space: nowrap;
}
.tab:hover {
    background: #e3f2fd;
    color: var(--blue);
}
.tab.active {
    color: var(--blue);
    border-bottom-color: var(--blue);
    background: #fff;
}
.tab-content {
    display: none;
    padding: 20px;
}
.tab-content.active {
    display: block;
}

/* Data Table */
.data-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}
.data-table th {
    background: #f0f7ff;
    color: var(--blue);
    font-weight: 600;
    padding: 12px 15px;
    text-align: left;
    border-bottom: 2px solid var(--blue);
    position: sticky;
    top: 0;
    z-index: 10;
}
.data-table td {
    padding: 12px 15px;
    border-bottom: 1px solid #eee;
    vertical-align: middle;
}
.data-table tr:hover {
    background: #f9f9f9;
}
.data-table tr:nth-child(even) {
    background: #f8fdff;
}
.data-table .actions {
    display: flex;
    gap: 8px;
    white-space: nowrap;
}
.action-btn {
    padding: 6px 12px;
    border: none;
    border-radius: 4px;
    font-size: 13px;
    cursor: pointer;
    transition: 0.3s;
    display: flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
}
.edit-btn {
    background: var(--blue);
    color: white;
}
.edit-btn:hover {
    background: #0056b3;
}
.delete-btn {
    background: var(--red);
    color: white;
}
.delete-btn:hover {
    background: #b71c1c;
}
.no-data {
    text-align: center;
    padding: 40px;
    color: #999;
}
.no-data i {
    font-size: 48px;
    margin-bottom: 15px;
    display: block;
}
.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.badge.active {
    background: #e8f5e9;
    color: var(--green);
}
.badge.inactive {
    background: #ffebee;
    color: var(--red);
}

/* Scrollable Table Container */
.table-container {
    max-height: 600px;
    overflow-y: auto;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    margin-top: 15px;
}
.table-container::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}
.table-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}
.table-container::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 4px;
}
.table-container::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Modal */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.7);
    z-index: 3000;
    overflow: auto;
    animation: fadeIn 0.3s ease;
}
.modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
}
.modal-content {
    background: #fff;
    border-radius: 12px;
    width: 90%;
    max-width: 750px;
    padding: 30px;
    position: relative;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    border-top: 5px solid var(--blue);
    animation: slideUp 0.3s ease;
    margin: 20px auto;
    max-height: 90vh;
    overflow-y: auto;
}
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
@keyframes slideUp {
    from { transform: translateY(30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.close-modal {
    position: absolute;
    top: 15px;
    right: 20px;
    font-size: 28px;
    cursor: pointer;
    color: var(--red);
    transition: .2s;
    background: none;
    border: none;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
}
.close-modal:hover {
    background: #f5f5f5;
    transform: scale(1.1);
}

/* Form */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}
.form-full {
    grid-column: 1 / -1;
}
#timetableForm label {
    font-weight: 600;
    color: var(--blue);
    font-size: 13px;
    margin-bottom: 5px;
    display: block;
}
#timetableForm input, #timetableForm select, #timetableForm textarea {
    width: 100%;
    padding: 9px 12px;
    border: 1.5px solid #ddd;
    border-radius: 8px;
    font-size: 14px;
    background: #f9f9f9;
    transition: 0.2s;
}
#timetableForm input:focus, #timetableForm select:focus, #timetableForm textarea:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(0,114,206,0.15);
    outline: none;
    background: #fff;
}
.save-btn {
    background: var(--green);
    color: #fff;
    border: none;
    padding: 13px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: .3s;
    font-size: 15px;
    margin-top: 15px;
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.save-btn:hover {
    background: var(--light-green);
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,132,61,0.2);
}
.required::after {
    content: " *";
    color: var(--red);
}
.section-header {
    font-size: 16px;
    font-weight: 600;
    color: var(--blue);
    margin: 20px 0 15px 0;
    padding-bottom: 8px;
    border-bottom: 2px solid #f0f7ff;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Alert */
.alert {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 14px 22px;
    border-radius: 10px;
    color: white;
    font-weight: 600;
    z-index: 1000;
    box-shadow: 0 5px 15px rgba(0,0,0,0.15);
    animation: slideIn 0.3s ease-out;
    display: flex;
    align-items: center;
    gap: 12px;
    max-width: 400px;
}
.alert.success {
    background: var(--green);
    border-left: 5px solid #00612c;
}
.alert.error {
    background: var(--red);
    border-left: 5px solid #8e1c1c;
}
.alert.warning {
    background: var(--orange);
    border-left: 5px solid #cc7a00;
}
@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* Responsive */
@media (max-width: 900px) {
    .form-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    .modal-content {
        width: 95%;
        padding: 25px;
        margin: 15px;
    }
}
@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding: 15px;
    }
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    .add-btn {
        align-self: flex-end;
    }
    .grid {
        grid-template-columns: 1fr;
    }
    .tabs {
        flex-wrap: nowrap;
        overflow-x: auto;
    }
    .tab {
        padding: 10px 15px;
        font-size: 14px;
    }
    .data-table th,
    .data-table td {
        padding: 8px 10px;
        font-size: 14px;
    }
    .action-btn {
        padding: 4px 8px;
        font-size: 12px;
    }
    .table-container {
        max-height: 400px;
    }
}

/* Scrollbar Styling */
.modal-content::-webkit-scrollbar,
.table-container::-webkit-scrollbar {
    width: 8px;
}
.modal-content::-webkit-scrollbar-track,
.table-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}
.modal-content::-webkit-scrollbar-thumb,
.table-container::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 4px;
}
.modal-content::-webkit-scrollbar-thumb:hover,
.table-container::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Mobile Table */
@media (max-width: 600px) {
    .table-container {
        display: block;
        overflow-x: auto;
    }
    .data-table {
        min-width: 800px;
    }
    .actions {
        flex-direction: column;
        gap: 5px;
    }
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
    <div class="page-header">
        <h1>Class Timetable</h1>
        <button class="add-btn" onclick="openModal()">
            <i class="fas fa-plus"></i> Add Schedule
        </button>
    </div>

    <!-- HIERARCHICAL FILTERS -->
    <div class="filter-box">
        <form method="GET" id="filterForm">
            <div class="grid">
                <!-- CAMPUS -->
                <div>
                    <label for="campus_id">Campus</label>
                    <select name="campus_id" id="filter_campus" onchange="onFilterCampusChange()">
                        <option value="">All Campuses</option>
                        <?php foreach($campuses as $campus): ?>
                        <option value="<?= $campus['campus_id'] ?>" 
                            <?= ($selectedCampus == $campus['campus_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($campus['campus_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- FACULTY -->
                <div>
                    <label for="faculty_id">Faculty</label>
                    <select name="faculty_id" id="filter_faculty" 
                            onchange="onFilterFacultyChange()" 
                            <?= empty($selectedCampus) ? 'disabled' : '' ?>>
                        <option value="">All Faculties</option>
                        <?php foreach($filterFaculties as $faculty): ?>
                        <option value="<?= $faculty['faculty_id'] ?>" 
                            <?= ($selectedFaculty == $faculty['faculty_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($faculty['faculty_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- DEPARTMENT -->
                <div>
                    <label for="department_id">Department</label>
                    <select name="department_id" id="filter_department" 
                            onchange="onFilterDepartmentChange()" 
                            <?= empty($selectedFaculty) ? 'disabled' : '' ?>>
                        <option value="">All Departments</option>
                        <?php foreach($filterDepartments as $dept): ?>
                        <option value="<?= $dept['department_id'] ?>" 
                            <?= ($selectedDepartment == $dept['department_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['department_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- PROGRAM -->
                <div>
                    <label for="program_id">Program</label>
                    <select name="program_id" id="filter_program" 
                            onchange="onFilterProgramChange()" 
                            <?= empty($selectedDepartment) ? 'disabled' : '' ?>>
                        <option value="">All Programs</option>
                        <?php foreach($filterPrograms as $program): ?>
                        <option value="<?= $program['program_id'] ?>" 
                            <?= ($selectedProgram == $program['program_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($program['program_name']) ?> 
                            (<?= htmlspecialchars($program['program_code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- CLASS -->
                <div>
                    <label for="class_id">Class</label>
                    <select name="class_id" id="filter_class" 
                            <?= empty($selectedProgram) ? 'disabled' : '' ?>>
                        <option value="">All Classes</option>
                        <?php foreach($filterClasses as $class): ?>
                        <option value="<?= $class['class_id'] ?>" 
                            <?= ($selectedClass == $class['class_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($class['class_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- TERM -->
                <div>
                    <label for="academic_term_id">Term</label>
                    <select name="academic_term_id" id="filter_term">
                        <option value="">All Terms</option>
                        <?php foreach($terms as $term): ?>
                        <option value="<?= $term['academic_term_id'] ?>" 
                            <?= ($selectedTerm == $term['academic_term_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($term['term_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- BUTTONS -->
                <div style="align-self:end; display: flex; gap: 10px;">
                    <button type="submit" class="btn blue">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                    <button type="button" class="btn" onclick="resetFilters()" 
                            style="background: #eee; color: #666;">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- TIMETABLE TABS -->
    <div class="timetable-container">
        <div class="timetable-header">
            <i class="fas fa-calendar-alt"></i> Weekly Schedule
            <?php if($selectedCampus): ?>
                <span style="font-size: 14px; font-weight: normal; float: right;">
                    <?php 
                    $campus_name = $pdo->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
                    $campus_name->execute([$selectedCampus]);
                    echo htmlspecialchars($campus_name->fetchColumn());
                    ?>
                </span>
            <?php endif; ?>
        </div>
        
        <!-- Tabs Navigation -->
        <div class="tabs" id="dayTabs">
            <?php foreach($daysOrder as $index => $day): ?>
                <button class="tab <?= $index === 0 ? 'active' : '' ?>" onclick="switchTab('<?= $day ?>')">
                    <?= $day ?>
                    <?php if(isset($timetableByDay[$day])): ?>
                        <span style="background: var(--blue); color: white; padding: 2px 6px; border-radius: 10px; font-size: 11px; margin-left: 5px;">
                            <?= count($timetableByDay[$day]) ?>
                        </span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
        
        <!-- Tab Contents -->
        <?php foreach($daysOrder as $index => $day): ?>
            <div class="tab-content <?= $index === 0 ? 'active' : '' ?>" id="tab-<?= $day ?>">
                <?php if(isset($timetableByDay[$day]) && !empty($timetableByDay[$day])): ?>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Time</th>
                                    <th>Subject</th>
                                    <th>Class</th>
                                    <th>Teacher</th>
                                    <th>Room</th>
                                    <th>Campus</th>
                                    <th>Faculty</th>
                                    <th>Department</th>
                                    <th>Program</th>
                                    <th>Term</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($timetableByDay[$day] as $index => $schedule): ?>
                                <tr>
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <strong><?= date('h:i A', strtotime($schedule['start_time'])) ?></strong> - 
                                        <strong><?= date('h:i A', strtotime($schedule['end_time'])) ?></strong>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($schedule['subject_name']) ?>
                                        <div style="font-size: 12px; color: #666;">
                                            Code: <?= htmlspecialchars($schedule['subject_code']) ?>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($schedule['class_name']) ?></td>
                                    <td><?= htmlspecialchars($schedule['teacher_name'] ?? 'N/A') ?></td>
                                    <td>
                                        <?php if(!empty($schedule['room_name'])): ?>
                                            <?= htmlspecialchars($schedule['room_name']) ?>
                                            <?php if(!empty($schedule['room_code'])): ?>
                                                (<?= htmlspecialchars($schedule['room_code']) ?>)
                                            <?php endif; ?>
                                            <?php if(!empty($schedule['room_type'])): ?>
                                                <div style="font-size: 12px; color: #666;"><?= htmlspecialchars($schedule['room_type']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($schedule['campus_name']) ?></td>
                                    <td><?= htmlspecialchars($schedule['faculty_name']) ?></td>
                                    <td><?= htmlspecialchars($schedule['department_name']) ?></td>
                                    <td><?= htmlspecialchars($schedule['program_name']) ?></td>
                                    <td><?= htmlspecialchars($schedule['term_name']) ?></td>
                                    <td>
                                        <span class="badge <?= $schedule['status'] ?>">
                                            <?= ucfirst($schedule['status']) ?>
                                        </span>
                                    </td>
                                    <td class="actions">
                                        <button class="action-btn edit-btn" onclick="editSchedule(<?= htmlspecialchars(json_encode($schedule), ENT_QUOTES, 'UTF-8') ?>)">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirmDelete()">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="timetable_id" value="<?= $schedule['timetable_id'] ?>">
                                            <button class="action-btn delete-btn" type="submit">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="far fa-calendar-times"></i>
                        <h3>No classes scheduled for <?= $day ?></h3>
                        <p>Click "Add Schedule" button to create a new schedule for this day.</p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- 🔹 Add/Edit Modal -->
<div class="modal" id="scheduleModal">
    <div class="modal-content">
        <button class="close-modal" onclick="closeModal()">&times;</button>
        <h2 style="color: var(--blue); margin-bottom: 20px; font-size: 24px;" id="modalTitle">Add New Schedule</h2>
        
        <form method="POST" id="timetableForm" onsubmit="return validateForm()">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="timetable_id" id="timetable_id">
            
            <!-- HIERARCHY SECTION -->
            <div class="form-grid">
                <!-- CAMPUS -->
                <div>
                    <label for="campus_id" class="required">Campus</label>
                    <select name="campus_id" id="campus_id" required onchange="onCampusChange()">
                        <option value="">Select Campus</option>
                        <?php foreach($campuses as $campus): ?>
                        <option value="<?= $campus['campus_id'] ?>">
                            <?= htmlspecialchars($campus['campus_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- FACULTY -->
                <div>
                    <label for="faculty_id" class="required">Faculty</label>
                    <select name="faculty_id" id="faculty_id" required 
                            onchange="onFacultyChange()" disabled>
                        <option value="">Select Faculty</option>
                    </select>
                </div>
                
                <!-- DEPARTMENT -->
                <div>
                    <label for="department_id" class="required">Department</label>
                    <select name="department_id" id="department_id" required 
                            onchange="onDepartmentChange()" disabled>
                        <option value="">Select Department</option>
                    </select>
                </div>
                
                <!-- PROGRAM -->
                <div>
                    <label for="program_id" class="required">Program</label>
                    <select name="program_id" id="program_id" required 
                            onchange="onProgramChange()" disabled>
                        <option value="">Select Program</option>
                    </select>
                </div>
                
                <!-- CLASS -->
                <div>
                    <label for="class_id" class="required">Class</label>
                    <select name="class_id" id="class_id" required 
                            onchange="onClassChange()" disabled>
                        <option value="">Select Class</option>
                    </select>
                </div>
                
                <!-- SUBJECT -->
                <div>
                    <label for="subject_id" class="required">Subject</label>
                    <select name="subject_id" id="subject_id" required disabled>
                        <option value="">Select Subject</option>
                    </select>
                </div>
            </div>
            
            <!-- SCHEDULE DETAILS SECTION -->
            <div class="form-grid">
                <!-- TERM -->
                <div>
                    <label for="academic_term_id" class="required">Academic Term</label>
                    <select name="academic_term_id" id="academic_term_id" required>
                        <option value="">Select Term</option>
                        <?php foreach($allTerms as $term): ?>
                        <option value="<?= $term['academic_term_id'] ?>">
                            <?= htmlspecialchars($term['term_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- DAY -->
                <div>
                    <label for="day_of_week" class="required">Day of Week</label>
                    <select name="day_of_week" id="day_of_week" required>
                        <option value="">Select Day</option>
                        <?php foreach($allDays as $day): ?>
                        <option value="<?= $day ?>"><?= $day ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- START TIME -->
                <div>
                    <label for="start_time" class="required">Start Time</label>
                    <input type="time" name="start_time" id="start_time" required>
                </div>
                
                <!-- END TIME -->
                <div>
                    <label for="end_time" class="required">End Time</label>
                    <input type="time" name="end_time" id="end_time" required>
                </div>
                
                <!-- TEACHER -->
                <div>
                    <label for="teacher_id">Teacher</label>
                    <select name="teacher_id" id="teacher_id">
                        <option value="">Select Teacher</option>
                    </select>
                </div>
                
                <!-- ROOM -->
                <div>
                    <label for="room_id">Room</label>
                    <select name="room_id" id="room_id">
                        <option value="">Select Room</option>
                    </select>
                </div>
                
                <!-- STATUS -->
                <div>
                    <label for="status" class="required">Status</label>
                    <select name="status" id="status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            
            <div class="form-full">
                <button type="submit" class="save-btn">
                    <i class="fas fa-save"></i> Save Schedule
                </button>
            </div>
        </form>
    </div>
</div>

<?php if($message): ?>
<div class="alert <?= htmlspecialchars($type) ?>" id="alertMessage">
    <?php if($type == 'success'): ?>
        <i class="fas fa-check-circle"></i>
    <?php elseif($type == 'error'): ?>
        <i class="fas fa-exclamation-circle"></i>
    <?php elseif($type == 'warning'): ?>
        <i class="fas fa-exclamation-triangle"></i>
    <?php endif; ?>
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php include('../includes/footer.php'); ?>

<script>
// Get current filename for AJAX calls
const currentFile = window.location.pathname.split('/').pop();

// Tab switching function
function switchTab(day) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tabs
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Show selected tab content
    document.getElementById('tab-' + day).classList.add('active');
    
    // Add active class to clicked tab
    event.target.classList.add('active');
}

function openModal() {
    const modal = document.getElementById('scheduleModal');
    modal.classList.add('show');
    document.getElementById('modalTitle').innerText = "Add New Schedule";
    document.getElementById('formAction').value = "add";
    document.getElementById('timetableForm').reset();
    document.getElementById('timetable_id').value = "";
    
    // Reset dependent dropdowns
    resetHierarchy();
    
    // Add padding to body to prevent scrollbar from disappearing
    document.body.style.overflow = 'hidden';
    
    // Enable teacher dropdown immediately (teachers are campus-independent)
    loadTeachers();
}

function closeModal() {
    const modal = document.getElementById('scheduleModal');
    modal.classList.remove('show');
    
    // Remove padding from body
    document.body.style.overflow = '';
}

function editSchedule(data) {
    try {
        const modal = document.getElementById('scheduleModal');
        modal.classList.add('show');
        document.getElementById('modalTitle').innerText = "Edit Schedule";
        document.getElementById('formAction').value = "update";
        
        // Set basic values
        document.getElementById('timetable_id').value = data.timetable_id;
        document.getElementById('academic_term_id').value = data.academic_term_id;
        document.getElementById('day_of_week').value = data.day_of_week;
        document.getElementById('start_time').value = data.start_time.substring(0, 5);
        document.getElementById('end_time').value = data.end_time.substring(0, 5);
        document.getElementById('status').value = data.status;
        
        // Set hierarchy and load dependent data
        if (data.campus_id) {
            document.getElementById('campus_id').value = data.campus_id;
            loadHierarchyForEdit(data);
        }
        
        // Add padding to body to prevent scrollbar from disappearing
        document.body.style.overflow = 'hidden';
    } catch (error) {
        console.error('Error in editSchedule:', error);
        alert('Error loading schedule data. Please try again.');
    }
}

// Close modal when clicking outside
window.onclick = function(e) {
    const modal = document.getElementById('scheduleModal');
    if (e.target === modal) closeModal();
}

// Close modal with ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

// Delete confirmation
function confirmDelete() {
    return confirm('Are you sure you want to delete this schedule? This action cannot be undone.');
}

// ===========================================
// AJAX FUNCTIONS - FULL HIERARCHY
// ===========================================

function onCampusChange() {
    const campusId = document.getElementById('campus_id').value;
    const facultySelect = document.getElementById('faculty_id');
    const deptSelect = document.getElementById('department_id');
    const programSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    const subjectSelect = document.getElementById('subject_id');
    const teacherSelect = document.getElementById('teacher_id');
    const roomSelect = document.getElementById('room_id');
    
    if (!campusId) {
        resetHierarchy();
        return;
    }
    
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = true;
    resetChildDropdowns(deptSelect, programSelect, classSelect, subjectSelect);
    
    fetch(`${currentFile}?ajax=get_faculties&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            facultySelect.innerHTML = '<option value="">Select Faculty</option>';
            
            if (data.status === 'success' && data.faculties.length > 0) {
                data.faculties.forEach(faculty => {
                    const option = document.createElement('option');
                    option.value = faculty.faculty_id;
                    option.textContent = faculty.faculty_name;
                    facultySelect.appendChild(option);
                });
                facultySelect.disabled = false;
            } else {
                facultySelect.innerHTML = '<option value="">No active faculties found</option>';
                facultySelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading faculties:', error);
            facultySelect.innerHTML = '<option value="">Error loading</option>';
            facultySelect.disabled = false;
        });
    
    // Load teachers when campus is selected
    loadTeachers();
    
    // Load rooms when campus is selected
    loadRooms(campusId, null, null);
}

function onFacultyChange() {
    const facultyId = document.getElementById('faculty_id').value;
    const campusId = document.getElementById('campus_id').value;
    const deptSelect = document.getElementById('department_id');
    const programSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    const subjectSelect = document.getElementById('subject_id');
    
    if (!facultyId || !campusId) {
        resetChildDropdowns(deptSelect, programSelect, classSelect, subjectSelect);
        return;
    }
    
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    resetChildDropdowns(programSelect, classSelect, subjectSelect);
    
    // Load departments
    fetch(`${currentFile}?ajax=get_departments&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            deptSelect.innerHTML = '<option value="">Select Department</option>';
            
            if (data.status === 'success' && data.departments.length > 0) {
                data.departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.department_name;
                    deptSelect.appendChild(option);
                });
                deptSelect.disabled = false;
            } else {
                deptSelect.innerHTML = '<option value="">No active departments found</option>';
                deptSelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading departments:', error);
            deptSelect.innerHTML = '<option value="">Error loading</option>';
            deptSelect.disabled = false;
        });
    
    // Update rooms with faculty filter
    const campusIdForRooms = document.getElementById('campus_id').value;
    loadRooms(campusIdForRooms, facultyId, null);
}

function onDepartmentChange() {
    const deptId = document.getElementById('department_id').value;
    const facultyId = document.getElementById('faculty_id').value;
    const campusId = document.getElementById('campus_id').value;
    const programSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    const subjectSelect = document.getElementById('subject_id');
    
    if (!deptId || !facultyId || !campusId) {
        resetChildDropdowns(programSelect, classSelect, subjectSelect);
        return;
    }
    
    programSelect.innerHTML = '<option value="">Loading...</option>';
    programSelect.disabled = true;
    resetChildDropdowns(classSelect, subjectSelect);
    
    fetch(`${currentFile}?ajax=get_programs&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            programSelect.innerHTML = '<option value="">Select Program</option>';
            
            if (data.status === 'success' && data.programs.length > 0) {
                data.programs.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = `${program.program_name} (${program.program_code})`;
                    programSelect.appendChild(option);
                });
                programSelect.disabled = false;
            } else {
                programSelect.innerHTML = '<option value="">No active programs found</option>';
                programSelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading programs:', error);
            programSelect.innerHTML = '<option value="">Error loading</option>';
            programSelect.disabled = false;
        });
    
    // Update rooms with department filter
    const campusIdForRooms = document.getElementById('campus_id').value;
    const facultyIdForRooms = document.getElementById('faculty_id').value;
    loadRooms(campusIdForRooms, facultyIdForRooms, deptId);
}

function onProgramChange() {
    const programId = document.getElementById('program_id').value;
    const deptId = document.getElementById('department_id').value;
    const facultyId = document.getElementById('faculty_id').value;
    const campusId = document.getElementById('campus_id').value;
    const classSelect = document.getElementById('class_id');
    const subjectSelect = document.getElementById('subject_id');
    
    if (!programId || !deptId || !facultyId || !campusId) {
        resetChildDropdowns(classSelect, subjectSelect);
        return;
    }
    
    classSelect.innerHTML = '<option value="">Loading...</option>';
    classSelect.disabled = true;
    subjectSelect.innerHTML = '<option value="">Select Subject</option>';
    subjectSelect.disabled = true;
    
    fetch(`${currentFile}?ajax=get_classes&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            classSelect.innerHTML = '<option value="">Select Class</option>';
            
            if (data.status === 'success' && data.classes.length > 0) {
                data.classes.forEach(cls => {
                    const option = document.createElement('option');
                    option.value = cls.class_id;
                    option.textContent = cls.class_name;
                    classSelect.appendChild(option);
                });
                classSelect.disabled = false;
            } else {
                classSelect.innerHTML = '<option value="">No active classes found</option>';
                classSelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading classes:', error);
            classSelect.innerHTML = '<option value="">Error loading</option>';
            classSelect.disabled = false;
        });
}

function onClassChange() {
    const classId = document.getElementById('class_id').value;
    const programId = document.getElementById('program_id').value;
    const deptId = document.getElementById('department_id').value;
    const facultyId = document.getElementById('faculty_id').value;
    const campusId = document.getElementById('campus_id').value;
    const subjectSelect = document.getElementById('subject_id');
    
    if (!classId || !programId || !deptId || !facultyId || !campusId) {
        subjectSelect.innerHTML = '<option value="">Select Subject</option>';
        subjectSelect.disabled = true;
        return;
    }
    
    subjectSelect.innerHTML = '<option value="">Loading...</option>';
    subjectSelect.disabled = true;
    
    // Load subjects for this class
    fetch(`${currentFile}?ajax=get_subjects&class_id=${classId}&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            subjectSelect.innerHTML = '<option value="">Select Subject</option>';
            
            if (data.status === 'success' && data.subjects.length > 0) {
                data.subjects.forEach(subject => {
                    const option = document.createElement('option');
                    option.value = subject.subject_id;
                    option.textContent = `${subject.subject_name} (${subject.subject_code})`;
                    subjectSelect.appendChild(option);
                });
                subjectSelect.disabled = false;
            } else {
                subjectSelect.innerHTML = '<option value="">No active subjects found</option>';
                subjectSelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading subjects:', error);
            subjectSelect.innerHTML = '<option value="">Error loading</option>';
            subjectSelect.disabled = false;
        });
}

function loadTeachers() {
    const teacherSelect = document.getElementById('teacher_id');
    
    teacherSelect.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`${currentFile}?ajax=get_teachers`)
        .then(response => response.json())
        .then(data => {
            teacherSelect.innerHTML = '<option value="">Select Teacher</option>';
            teacherSelect.innerHTML += '<option value="">No Teacher</option>';
            
            if (data.status === 'success' && data.teachers.length > 0) {
                data.teachers.forEach(teacher => {
                    const option = document.createElement('option');
                    option.value = teacher.teacher_id;
                    option.textContent = teacher.teacher_name;
                    teacherSelect.appendChild(option);
                });
                teacherSelect.disabled = false;
            } else {
                teacherSelect.innerHTML = '<option value="">No active teachers found</option>';
                teacherSelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading teachers:', error);
            teacherSelect.innerHTML = '<option value="">Error loading</option>';
            teacherSelect.disabled = false;
        });
}

function loadRooms(campusId, facultyId = null, departmentId = null) {
    const roomSelect = document.getElementById('room_id');
    
    if (!campusId) {
        roomSelect.innerHTML = '<option value="">Select Room</option>';
        roomSelect.disabled = true;
        return;
    }
    
    roomSelect.innerHTML = '<option value="">Loading...</option>';
    roomSelect.disabled = true;
    
    let url = `${currentFile}?ajax=get_rooms&campus_id=${campusId}`;
    if (facultyId) {
        url += `&faculty_id=${facultyId}`;
    }
    if (departmentId) {
        url += `&department_id=${departmentId}`;
    }
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            roomSelect.innerHTML = '<option value="">Select Room</option>';
            roomSelect.innerHTML += '<option value="">No Room</option>';
            
            if (data.status === 'success' && data.rooms.length > 0) {
                data.rooms.forEach(room => {
                    const option = document.createElement('option');
                    option.value = room.room_id;
                    let displayText = room.room_name;
                    if (room.room_code) {
                        displayText += ` (${room.room_code})`;
                    }
                    if (room.room_type) {
                        displayText += ` - ${room.room_type}`;
                    }
                    if (room.capacity) {
                        displayText += ` - ${room.capacity} seats`;
                    }
                    option.textContent = displayText;
                    roomSelect.appendChild(option);
                });
                roomSelect.disabled = false;
            } else {
                roomSelect.innerHTML = '<option value="">No rooms found for this campus</option>';
                roomSelect.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error loading rooms:', error);
            roomSelect.innerHTML = '<option value="">Error loading</option>';
            roomSelect.disabled = false;
        });
}

function loadHierarchyForEdit(data) {
    const campusId = data.campus_id;
    const facultyId = data.faculty_id;
    const deptId = data.department_id;
    const programId = data.program_id;
    const classId = data.class_id;
    const subjectId = data.subject_id;
    const teacherId = data.teacher_id;
    const roomId = data.room_id;
    
    // Set campus and load faculties
    fetch(`${currentFile}?ajax=get_faculties&campus_id=${campusId}`)
        .then(response => response.json())
        .then(facultyData => {
            const facultySelect = document.getElementById('faculty_id');
            facultySelect.innerHTML = '<option value="">Select Faculty</option>';
            
            if (facultyData.status === 'success' && facultyData.faculties.length > 0) {
                facultyData.faculties.forEach(faculty => {
                    const option = document.createElement('option');
                    option.value = faculty.faculty_id;
                    option.textContent = faculty.faculty_name;
                    facultySelect.appendChild(option);
                });
                facultySelect.disabled = false;
                facultySelect.value = facultyId;
                
                // Load departments
                return fetch(`${currentFile}?ajax=get_departments&faculty_id=${facultyId}&campus_id=${campusId}`);
            }
        })
        .then(response => response.json())
        .then(deptData => {
            const deptSelect = document.getElementById('department_id');
            deptSelect.innerHTML = '<option value="">Select Department</option>';
            
            if (deptData.status === 'success' && deptData.departments.length > 0) {
                deptData.departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.department_name;
                    deptSelect.appendChild(option);
                });
                deptSelect.disabled = false;
                deptSelect.value = deptId;
                
                // Load programs
                return fetch(`${currentFile}?ajax=get_programs&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`);
            }
        })
        .then(response => response.json())
        .then(programData => {
            const programSelect = document.getElementById('program_id');
            programSelect.innerHTML = '<option value="">Select Program</option>';
            
            if (programData.status === 'success' && programData.programs.length > 0) {
                programData.programs.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = `${program.program_name} (${program.program_code})`;
                    programSelect.appendChild(option);
                });
                programSelect.disabled = false;
                programSelect.value = programId;
                
                // Load classes
                return fetch(`${currentFile}?ajax=get_classes&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`);
            }
        })
        .then(response => response.json())
        .then(classData => {
            const classSelect = document.getElementById('class_id');
            classSelect.innerHTML = '<option value="">Select Class</option>';
            
            if (classData.status === 'success' && classData.classes.length > 0) {
                classData.classes.forEach(cls => {
                    const option = document.createElement('option');
                    option.value = cls.class_id;
                    option.textContent = cls.class_name;
                    classSelect.appendChild(option);
                });
                classSelect.disabled = false;
                classSelect.value = classId;
                
                // Load subjects
                return fetch(`${currentFile}?ajax=get_subjects&class_id=${classId}&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`);
            }
        })
        .then(response => response.json())
        .then(subjectData => {
            const subjectSelect = document.getElementById('subject_id');
            subjectSelect.innerHTML = '<option value="">Select Subject</option>';
            
            if (subjectData.status === 'success' && subjectData.subjects.length > 0) {
                subjectData.subjects.forEach(subject => {
                    const option = document.createElement('option');
                    option.value = subject.subject_id;
                    option.textContent = `${subject.subject_name} (${subject.subject_code})`;
                    subjectSelect.appendChild(option);
                });
                subjectSelect.disabled = false;
                subjectSelect.value = subjectId;
            }
            
            // Load teachers
            return fetch(`${currentFile}?ajax=get_teachers`);
        })
        .then(response => response.json())
        .then(teacherData => {
            const teacherSelect = document.getElementById('teacher_id');
            teacherSelect.innerHTML = '<option value="">Select Teacher</option>';
            teacherSelect.innerHTML += '<option value="">No Teacher</option>';
            
            if (teacherData.status === 'success' && teacherData.teachers.length > 0) {
                teacherData.teachers.forEach(teacher => {
                    const option = document.createElement('option');
                    option.value = teacher.teacher_id;
                    option.textContent = teacher.teacher_name;
                    teacherSelect.appendChild(option);
                });
                teacherSelect.disabled = false;
                if (teacherId) {
                    teacherSelect.value = teacherId;
                } else {
                    teacherSelect.value = "";
                }
            }
            
            // Load rooms
            return fetch(`${currentFile}?ajax=get_rooms&campus_id=${campusId}&faculty_id=${facultyId}&department_id=${deptId}`);
        })
        .then(response => response.json())
        .then(roomData => {
            const roomSelect = document.getElementById('room_id');
            roomSelect.innerHTML = '<option value="">Select Room</option>';
            roomSelect.innerHTML += '<option value="">No Room</option>';
            
            if (roomData.status === 'success' && roomData.rooms.length > 0) {
                roomData.rooms.forEach(room => {
                    const option = document.createElement('option');
                    option.value = room.room_id;
                    let displayText = room.room_name;
                    if (room.room_code) {
                        displayText += ` (${room.room_code})`;
                    }
                    if (room.room_type) {
                        displayText += ` - ${room.room_type}`;
                    }
                    if (room.capacity) {
                        displayText += ` - ${room.capacity} seats`;
                    }
                    option.textContent = displayText;
                    roomSelect.appendChild(option);
                });
                roomSelect.disabled = false;
                if (roomId) {
                    roomSelect.value = roomId;
                } else {
                    roomSelect.value = "";
                }
            }
        })
        .catch(error => {
            console.error('Error loading hierarchy:', error);
        });
}

// Helper functions
function resetHierarchy() {
    const facultySelect = document.getElementById('faculty_id');
    const deptSelect = document.getElementById('department_id');
    const programSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    const subjectSelect = document.getElementById('subject_id');
    const teacherSelect = document.getElementById('teacher_id');
    const roomSelect = document.getElementById('room_id');
    
    facultySelect.innerHTML = '<option value="">Select Faculty</option>';
    facultySelect.disabled = true;
    deptSelect.innerHTML = '<option value="">Select Department</option>';
    deptSelect.disabled = true;
    programSelect.innerHTML = '<option value="">Select Program</option>';
    programSelect.disabled = true;
    classSelect.innerHTML = '<option value="">Select Class</option>';
    classSelect.disabled = true;
    subjectSelect.innerHTML = '<option value="">Select Subject</option>';
    subjectSelect.disabled = true;
    
    // Reset but don't disable teacher and room selects
    teacherSelect.innerHTML = '<option value="">Select Teacher</option>';
    teacherSelect.innerHTML += '<option value="">No Teacher</option>';
    teacherSelect.disabled = false;
    
    roomSelect.innerHTML = '<option value="">Select Room</option>';
    roomSelect.innerHTML += '<option value="">No Room</option>';
    roomSelect.disabled = false;
}

function resetChildDropdowns(...selects) {
    selects.forEach(select => {
        if (select) {
            select.innerHTML = '<option value="">Select ' + select.name.replace('_id', '').replace('_', ' ') + '</option>';
            select.disabled = true;
        }
    });
}

// ===========================================
// FILTER FUNCTIONS
// ===========================================

function onFilterCampusChange() {
    const campusId = document.getElementById('filter_campus').value;
    const facultySelect = document.getElementById('filter_faculty');
    const deptSelect = document.getElementById('filter_department');
    const programSelect = document.getElementById('filter_program');
    const classSelect = document.getElementById('filter_class');
    
    if (!campusId) {
        facultySelect.disabled = true;
        deptSelect.disabled = true;
        programSelect.disabled = true;
        classSelect.disabled = true;
        return;
    }
    
    // Enable faculty select and load faculties
    facultySelect.disabled = false;
    deptSelect.disabled = true;
    programSelect.disabled = true;
    classSelect.disabled = true;
    
    // Load faculties for selected campus
    fetch(`${currentFile}?ajax=get_faculties&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            facultySelect.innerHTML = '<option value="">All Faculties</option>';
            if (data.status === 'success' && data.faculties.length > 0) {
                data.faculties.forEach(faculty => {
                    const option = document.createElement('option');
                    option.value = faculty.faculty_id;
                    option.textContent = faculty.faculty_name;
                    facultySelect.appendChild(option);
                });
            }
        });
}

function onFilterFacultyChange() {
    const campusId = document.getElementById('filter_campus').value;
    const facultyId = document.getElementById('filter_faculty').value;
    const deptSelect = document.getElementById('filter_department');
    const programSelect = document.getElementById('filter_program');
    const classSelect = document.getElementById('filter_class');
    
    if (!facultyId) {
        deptSelect.disabled = true;
        programSelect.disabled = true;
        classSelect.disabled = true;
        return;
    }
    
    // Enable department select and load departments
    deptSelect.disabled = false;
    programSelect.disabled = true;
    classSelect.disabled = true;
    
    // Load departments for selected faculty
    fetch(`${currentFile}?ajax=get_departments&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            deptSelect.innerHTML = '<option value="">All Departments</option>';
            if (data.status === 'success' && data.departments.length > 0) {
                data.departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.department_name;
                    deptSelect.appendChild(option);
                });
            }
        });
}

function onFilterDepartmentChange() {
    const campusId = document.getElementById('filter_campus').value;
    const facultyId = document.getElementById('filter_faculty').value;
    const deptId = document.getElementById('filter_department').value;
    const programSelect = document.getElementById('filter_program');
    const classSelect = document.getElementById('filter_class');
    
    if (!deptId) {
        programSelect.disabled = true;
        classSelect.disabled = true;
        return;
    }
    
    // Enable program select and load programs
    programSelect.disabled = false;
    classSelect.disabled = true;
    
    // Load programs for selected department
    fetch(`${currentFile}?ajax=get_programs&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            programSelect.innerHTML = '<option value="">All Programs</option>';
            if (data.status === 'success' && data.programs.length > 0) {
                data.programs.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = `${program.program_name} (${program.program_code})`;
                    programSelect.appendChild(option);
                });
            }
        });
}

function onFilterProgramChange() {
    const campusId = document.getElementById('filter_campus').value;
    const facultyId = document.getElementById('filter_faculty').value;
    const deptId = document.getElementById('filter_department').value;
    const programId = document.getElementById('filter_program').value;
    const classSelect = document.getElementById('filter_class');
    
    if (!programId) {
        classSelect.disabled = true;
        return;
    }
    
    // Enable class select and load classes
    classSelect.disabled = false;
    
    // Load classes for selected program
    fetch(`${currentFile}?ajax=get_classes&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            classSelect.innerHTML = '<option value="">All Classes</option>';
            if (data.status === 'success' && data.classes.length > 0) {
                data.classes.forEach(cls => {
                    const option = document.createElement('option');
                    option.value = cls.class_id;
                    option.textContent = cls.class_name;
                    classSelect.appendChild(option);
                });
            }
        });
}

function resetFilters() {
    window.location.href = window.location.pathname;
}

// Form validation
function validateForm() {
    const startTime = document.getElementById('start_time').value;
    const endTime = document.getElementById('end_time').value;
    const subjectId = document.getElementById('subject_id').value;
    const campusId = document.getElementById('campus_id').value;
    
    if (!campusId) {
        alert('Please select a campus.');
        document.getElementById('campus_id').focus();
        return false;
    }
    
    if (!subjectId) {
        alert('Please select a subject.');
        document.getElementById('subject_id').focus();
        return false;
    }
    
    if (!startTime || !endTime) {
        alert('Please enter both start and end times.');
        return false;
    }
    
    if (endTime <= startTime) {
        alert('End time must be later than start time.');
        return false;
    }
    
    return true;
}

// Auto-hide alert after 5 seconds
if (document.getElementById('alertMessage')) {
    setTimeout(() => {
        const alert = document.getElementById('alertMessage');
        if (alert) {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s';
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.parentNode.removeChild(alert);
                }
            }, 500);
        }
    }, 5000);
}

// Prevent form submission when pressing Enter key in non-form fields
document.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && 
        e.target.tagName !== 'SELECT' && e.target.tagName !== 'INPUT') {
        e.preventDefault();
    }
});

// Initialize filter dropdowns
document.addEventListener('DOMContentLoaded', function() {
    // Enable/disable filter dropdowns based on hierarchy
    const campusSelect = document.getElementById('filter_campus');
    const facultySelect = document.getElementById('filter_faculty');
    const deptSelect = document.getElementById('filter_department');
    const programSelect = document.getElementById('filter_program');
    const classSelect = document.getElementById('filter_class');
    
    if (campusSelect) {
        campusSelect.addEventListener('change', function() {
            facultySelect.disabled = !this.value;
            deptSelect.disabled = true;
            programSelect.disabled = true;
            classSelect.disabled = true;
        });
    }
    
    // Load teachers when page loads (for add modal)
    loadTeachers();
});

// Scroll to top when tab changes
function scrollToTop() {
    const container = document.querySelector('.table-container');
    if (container) {
        container.scrollTop = 0;
    }
}

// Attach scroll to top to tab click
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', scrollToTop);
    });
});
</script>
</body>
</html>
<?php ob_end_flush(); ?>