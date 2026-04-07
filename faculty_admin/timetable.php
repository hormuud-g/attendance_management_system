<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ===========================================
// SECURITY: ACCESS CONTROL
// ===========================================
if (empty($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

// Faculty Admin only
$role = strtolower($_SESSION['user']['role'] ?? '');
if ($role !== 'faculty_admin') {
    header("Location: ../unauthorized.php");
    exit;
}

$user = $_SESSION['user'] ?? [];

// Get faculty ID from session
$faculty_id = $user['linked_id'] ?? null;
if (!$faculty_id) {
    header("Location: ../login.php");
    exit;
}

// Get faculty details
$faculty_name = '';
$faculty_code = '';
$stmt = $pdo->prepare("SELECT faculty_name, faculty_code FROM faculties WHERE faculty_id = ?");
$stmt->execute([$faculty_id]);
$faculty_data = $stmt->fetch(PDO::FETCH_ASSOC);
$faculty_name = $faculty_data['faculty_name'] ?? 'Unknown Faculty';
$faculty_code = $faculty_data['faculty_code'] ?? '';

// Get all campuses for this faculty
$faculty_campuses = [];
$stmt = $pdo->prepare("
    SELECT c.campus_id, c.campus_name, c.campus_code 
    FROM campus c
    JOIN faculty_campus fc ON c.campus_id = fc.campus_id
    WHERE fc.faculty_id = ? AND c.status = 'active'
    ORDER BY c.campus_name
");
$stmt->execute([$faculty_id]);
$faculty_campuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
$campus_ids = array_column($faculty_campuses, 'campus_id');

$message = "";
$type = "";

/* ===========================================
   AJAX HANDLERS FOR HIERARCHY
=========================================== */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // GET FACULTIES BY CAMPUS (for faculty admin, only return current faculty)
    if ($_GET['ajax'] == 'get_faculties') {
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Campus ID required']);
            exit;
        }
        
        // For faculty admin, only return the current faculty
        $faculties = [
            ['faculty_id' => $faculty_id, 'faculty_name' => $faculty_name]
        ];
        
        echo json_encode(['status' => 'success', 'faculties' => $faculties]);
        exit;
    }
    
    // GET DEPARTMENTS BY FACULTY & CAMPUS (with campus name)
    if ($_GET['ajax'] == 'get_departments') {
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'Faculty ID and Campus ID required']);
            exit;
        }
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT department_id, department_name, campus_id
            FROM departments 
            WHERE faculty_id = ? 
            AND campus_id = ?
            AND status = 'active'
            ORDER BY department_name
        ");
        $stmt->execute([$faculty_id, $campus_id]);
        $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add campus name to each department for display
        foreach ($departments as &$dept) {
            $campus_stmt = $pdo->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
            $campus_stmt->execute([$dept['campus_id']]);
            $dept['campus_name'] = $campus_stmt->fetchColumn();
            $dept['display_name'] = $dept['department_name'] . ' (' . $dept['campus_name'] . ')';
        }
        
        echo json_encode(['status' => 'success', 'departments' => $departments]);
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
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
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
        
        echo json_encode(['status' => 'success', 'programs' => $programs]);
        exit;
    }
    
    // GET CLASSES BY PROGRAM, DEPARTMENT, FACULTY & CAMPUS (with study mode)
    if ($_GET['ajax'] == 'get_classes') {
        $program_id = $_GET['program_id'] ?? 0;
        $department_id = $_GET['department_id'] ?? 0;
        $faculty_id = $_GET['faculty_id'] ?? 0;
        $campus_id = $_GET['campus_id'] ?? 0;
        
        if (!$program_id || !$department_id || !$faculty_id || !$campus_id) {
            echo json_encode(['status' => 'error', 'message' => 'All IDs required']);
            exit;
        }
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT class_id, class_name, study_mode 
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
        
        // Format class names with study mode
        $formatted_classes = [];
        foreach ($classes as $class) {
            $formatted_classes[] = [
                'class_id' => $class['class_id'],
                'class_name' => $class['class_name'],
                'study_mode' => $class['study_mode'],
                'display_name' => $class['class_name'] . ' (' . $class['study_mode'] . ')'
            ];
        }
        
        echo json_encode(['status' => 'success', 'classes' => $formatted_classes]);
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
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
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
        
        // Check if campus belongs to this faculty
        if (!in_array($campus_id, $campus_ids)) {
            echo json_encode(['status' => 'error', 'message' => 'Access denied']);
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
            $faculty_id_post = $_POST['faculty_id'] ?? '';
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

            // Verify campus belongs to this faculty
            if (!in_array($campus_id, $campus_ids)) {
                throw new Exception("Access denied: Invalid campus selected!");
            }

            // Verify faculty ID matches current faculty
            if ($faculty_id_post != $faculty_id) {
                throw new Exception("Access denied: Invalid faculty selected!");
            }

            validateRequired([
                "Campus" => $campus_id,
                "Faculty" => $faculty_id_post,
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
            
            // Verify the timetable entry belongs to this faculty
            $check = $pdo->prepare("SELECT COUNT(*) FROM timetable WHERE timetable_id = ? AND faculty_id = ?");
            $check->execute([$id, $faculty_id]);
            if ($check->fetchColumn() == 0) {
                throw new Exception("Access denied: You can only delete schedules from your faculty!");
            }
            
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
$selectedCampus = $_GET['campus_id'] ?? null;
$selectedDepartment = $_GET['department_id'] ?? null;
$selectedProgram = $_GET['program_id'] ?? null;
$selectedClass = $_GET['class_id'] ?? null;
$selectedTerm = $_GET['academic_term_id'] ?? null;

/* ===========================================
   FETCH STATIC DATA
=========================================== */
// Campuses (only those belonging to this faculty)
$campuses = $faculty_campuses;

// Terms
$terms = $pdo->query("SELECT * FROM academic_term ORDER BY term_name DESC")->fetchAll(PDO::FETCH_ASSOC);

// For main filter dropdowns - Hierarchical loading
$filterDepartments = [];
if ($selectedCampus) {
    $filterDepartments = $pdo->prepare("
        SELECT department_id, department_name, campus_id
        FROM departments
        WHERE faculty_id = ? 
        AND campus_id = ?
        AND status = 'active'
        ORDER BY department_name ASC
    ");
    $filterDepartments->execute([$faculty_id, $selectedCampus]);
    $filterDepartments = $filterDepartments->fetchAll(PDO::FETCH_ASSOC);
    
    // Add campus name for display
    foreach ($filterDepartments as &$dept) {
        $campus_stmt = $pdo->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
        $campus_stmt->execute([$dept['campus_id']]);
        $dept['campus_name'] = $campus_stmt->fetchColumn();
    }
}

$filterPrograms = [];
if ($selectedDepartment && $selectedCampus) {
    $filterPrograms = $pdo->prepare("
        SELECT program_id, program_name, program_code
        FROM programs
        WHERE department_id = ? 
        AND faculty_id = ?
        AND campus_id = ?
        AND status = 'active'
        ORDER BY program_name ASC
    ");
    $filterPrograms->execute([$selectedDepartment, $faculty_id, $selectedCampus]);
    $filterPrograms = $filterPrograms->fetchAll(PDO::FETCH_ASSOC);
}

$filterClasses = [];
if ($selectedProgram && $selectedDepartment && $selectedCampus) {
    $filterClasses = $pdo->prepare("
        SELECT class_id, class_name, study_mode
        FROM classes
        WHERE program_id = ? 
        AND department_id = ?
        AND faculty_id = ?
        AND campus_id = ?
        AND status = 'Active'
        ORDER BY class_name ASC
    ");
    $filterClasses->execute([$selectedProgram, $selectedDepartment, $faculty_id, $selectedCampus]);
    $filterClasses = $filterClasses->fetchAll(PDO::FETCH_ASSOC);
}

// Other data (for modals - will be loaded dynamically via AJAX)
$allTerms = $pdo->query("SELECT * FROM academic_term ORDER BY term_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$allDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/* ===========================================
   FETCH TIMETABLE DATA WITH HIERARCHY
=========================================== */
// Build WHERE clause based on filters
$whereConditions = ["t.faculty_id = ?"];
$params = [$faculty_id];

if ($selectedCampus) {
    $whereConditions[] = "t.campus_id = ?";
    $params[] = $selectedCampus;
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

$whereClause = "WHERE " . implode(" AND ", $whereConditions);

$timetablesQuery = "
    SELECT t.*, 
           c.class_name, c.study_mode,
           ca.campus_name,
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

$stmt = $pdo->prepare($timetablesQuery);
$stmt->execute($params);
$timetables = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
<title>Class Timetable | Faculty Admin - <?= htmlspecialchars($faculty_name) ?> | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="../assets/css/header.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --green: #00843D;
    --blue: #0072CE;
    --red: #C62828;
    --orange: #FF9800;
    --light-green: #00A651;
    --bg: #F5F9F7;
    --dark: #333;
    --light: #f8f9fa;
    --border: #e0e0e0;
    --shadow: rgba(0, 0, 0, 0.08);
    --white: #fff;
    --gold: #FFB81C;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', 'Poppins', sans-serif;
}

body {
    background: var(--bg);
    color: var(--dark);
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

/* Page Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding: 20px;
    background: var(--white);
    border-radius: 12px;
    box-shadow: 0 4px 15px var(--shadow);
    border-left: 4px solid var(--green);
    flex-wrap: wrap;
    gap: 15px;
}

.page-header h1 {
    color: var(--blue);
    font-size: 24px;
    margin: 0;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.page-header h1 i {
    color: var(--green);
    background: rgba(0,132,61,0.1);
    padding: 10px;
    border-radius: 10px;
}

.faculty-badge {
    background: rgba(0, 114, 206, 0.1);
    color: var(--blue);
    padding: 6px 15px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    white-space: nowrap;
}

.campus-count {
    background: rgba(255, 184, 28, 0.1);
    color: var(--gold);
    padding: 6px 15px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: 600;
    white-space: nowrap;
}

.add-btn {
    background: linear-gradient(135deg, var(--green), var(--light-green));
    color: var(--white);
    border: none;
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: .3s;
    display: flex;
    align-items: center;
    gap: 10px;
    box-shadow: 0 4px 12px rgba(0,132,61,0.2);
}

.add-btn:hover {
    background: var(--light-green);
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(0,132,61,0.3);
}

/* Filter Box */
.filter-box {
    background: var(--white);
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px var(--shadow);
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

select, input[type="text"], input[type="time"] {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
    background: #f9f9f9;
    transition: 0.2s;
}

select:focus, input:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(0,114,206,0.12);
    outline: none;
    background: var(--white);
}

select:disabled {
    background: #e9ecef;
    cursor: not-allowed;
    opacity: 0.7;
}

.btn {
    border: none;
    padding: 10px 18px;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: .3s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn.blue {
    background: var(--blue);
    color: var(--white);
}

.btn.blue:hover {
    background: #0056b3;
    transform: translateY(-2px);
}

.btn:disabled {
    background: #ccc;
    cursor: not-allowed;
}

/* Campus Badge */
.campus-badge {
    background: rgba(255, 184, 28, 0.1);
    color: var(--gold);
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    display: inline-block;
    margin-left: 5px;
}

/* Study Mode Badge */
.study-mode-badge {
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    display: inline-block;
    margin-left: 5px;
    text-transform: uppercase;
}

.study-mode-full-time {
    background: rgba(0, 114, 206, 0.1);
    color: var(--blue);
    border: 1px solid rgba(0, 114, 206, 0.2);
}

.study-mode-part-time {
    background: rgba(255, 184, 28, 0.1);
    color: var(--gold);
    border: 1px solid rgba(255, 184, 28, 0.2);
}

/* Timetable Container */
.timetable-container {
    background: var(--white);
    border-radius: 12px;
    box-shadow: 0 4px 15px var(--shadow);
    overflow: hidden;
    border: 1px solid var(--border);
}

.timetable-header {
    background: linear-gradient(135deg, var(--blue), var(--green));
    color: white;
    padding: 15px 20px;
    font-weight: 600;
    font-size: 18px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

/* Tabs */
.tabs {
    display: flex;
    background: #f0f7ff;
    border-bottom: 1px solid #ddd;
    overflow-x: auto;
    padding: 5px 10px;
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
    font-size: 14px;
}
.tab:hover {
    background: #e3f2fd;
    color: var(--blue);
}
.tab.active {
    color: var(--blue);
    border-bottom-color: var(--blue);
    background: #fff;
    border-radius: 8px 8px 0 0;
}
.tab-content {
    display: none;
    padding: 20px;
}
.tab-content.active {
    display: block;
}

/* Data Table */
.table-container {
    max-height: 600px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: 8px;
    margin-top: 15px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}
.data-table th {
    background: #f0f7ff;
    color: var(--blue);
    font-weight: 600;
    padding: 14px 16px;
    text-align: left;
    border-bottom: 2px solid var(--blue);
    position: sticky;
    top: 0;
    z-index: 10;
    font-size: 14px;
}
.data-table td {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
    font-size: 14px;
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
    border-radius: 6px;
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
    transform: translateY(-2px);
}
.delete-btn {
    background: var(--red);
    color: white;
}
.delete-btn:hover {
    background: #b71c1c;
    transform: translateY(-2px);
}
.no-data {
    text-align: center;
    padding: 60px 20px;
    color: #999;
}
.no-data i {
    font-size: 48px;
    margin-bottom: 15px;
    display: block;
    color: #ddd;
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
    background: var(--white);
    border-radius: 16px;
    width: 90%;
    max-width: 800px;
    padding: 30px;
    position: relative;
    box-shadow: 0 15px 40px rgba(0,0,0,0.3);
    border-top: 5px solid var(--green);
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
    width: 40px;
    height: 40px;
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
    gap: 20px;
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
#timetableForm input, 
#timetableForm select, 
#timetableForm textarea {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid var(--border);
    border-radius: 8px;
    font-size: 14px;
    background: #f9f9f9;
    transition: 0.2s;
}
#timetableForm input:focus, 
#timetableForm select:focus, 
#timetableForm textarea:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(0,114,206,0.15);
    outline: none;
    background: var(--white);
}
.save-btn {
    background: linear-gradient(135deg, var(--green), var(--light-green));
    color: var(--white);
    border: none;
    padding: 14px;
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

/* Alert */
.alert {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 15px 25px;
    border-radius: 8px;
    color: white;
    font-weight: 600;
    z-index: 1000;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
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
@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* Responsive */
@media (max-width: 1024px) {
    .main-content {
        margin-left: 240px;
    }
    body.sidebar-collapsed .main-content {
        margin-left: 60px;
    }
}

@media (max-width: 900px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    .modal-content {
        width: 95%;
        padding: 25px;
    }
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0 !important;
        padding: 15px;
    }
    .page-header {
        flex-direction: column;
        align-items: flex-start;
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
        padding: 5px;
    }
    .tab {
        padding: 8px 15px;
        font-size: 13px;
    }
    .table-container {
        max-height: 400px;
    }
    .data-table {
        min-width: 1000px;
    }
    .actions {
        flex-direction: column;
        gap: 5px;
    }
    .action-btn {
        padding: 4px 8px;
        font-size: 12px;
    }
}

/* Scrollbar */
.modal-content::-webkit-scrollbar,
.table-container::-webkit-scrollbar {
    width: 8px;
    height: 8px;
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
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
    <div class="page-header">
        <h1>
            <i class="fas fa-calendar-alt"></i> 
            Class Timetable
        </h1>
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
                
                <!-- DEPARTMENT -->
                <div>
                    <label for="department_id">Department</label>
                    <select name="department_id" id="filter_department" 
                            onchange="onFilterDepartmentChange()" 
                            <?= empty($selectedCampus) ? 'disabled' : '' ?>>
                        <option value="">All Departments</option>
                        <?php foreach($filterDepartments as $dept): ?>
                        <option value="<?= $dept['department_id'] ?>" 
                            <?= ($selectedDepartment == $dept['department_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['department_name']) ?> (<?= htmlspecialchars($dept['campus_name']) ?>)
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
                            <?php if(!empty($class['study_mode'])): ?>
                                (<?= htmlspecialchars($class['study_mode']) ?>)
                            <?php endif; ?>
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
            <div><i class="fas fa-calendar-alt"></i> Weekly Schedule</div>
            <div style="font-size: 14px; font-weight: normal;">
                <?= count($timetables) ?> schedule<?= count($timetables) != 1 ? 's' : '' ?> found
            </div>
        </div>
        
        <!-- Tabs Navigation -->
        <div class="tabs" id="dayTabs">
            <?php foreach($daysOrder as $index => $day): ?>
                <button class="tab <?= $index === 0 ? 'active' : '' ?>" onclick="switchTab('<?= $day ?>')">
                    <?= $day ?>
                    <?php if(isset($timetableByDay[$day])): ?>
                        <span style="background: var(--blue); color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px;">
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
                                    <th>Department</th>
                                    <th>Program</th>
                                    <th>Campus</th>
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
                                    <td>
                                        <?= htmlspecialchars($schedule['class_name']) ?>
                                        <?php if(!empty($schedule['study_mode'])): ?>
                                            <span class="study-mode-badge study-mode-<?= strtolower($schedule['study_mode']) ?>">
                                                <?= htmlspecialchars($schedule['study_mode']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($schedule['teacher_name'] ?? 'N/A') ?></td>
                                    <td>
                                        <?php if(!empty($schedule['room_name'])): ?>
                                            <?= htmlspecialchars($schedule['room_name']) ?>
                                            <?php if(!empty($schedule['room_code'])): ?>
                                                <div style="font-size: 12px; color: #666;"><?= htmlspecialchars($schedule['room_code']) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($schedule['department_name']) ?>
                                        <span class="campus-badge"><?= htmlspecialchars($schedule['campus_name']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($schedule['program_name']) ?></td>
                                    <td><?= htmlspecialchars($schedule['campus_name']) ?></td>
                                    <td><?= htmlspecialchars($schedule['term_name']) ?></td>
                                    <td>
                                        <span class="badge <?= $schedule['status'] ?>">
                                            <?= ucfirst($schedule['status']) ?>
                                        </span>
                                    </td>
                                    <td class="actions">
                                        <button class="action-btn edit-btn" onclick='editSchedule(<?= htmlspecialchars(json_encode($schedule), ENT_QUOTES, 'UTF-8') ?>)'>
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
            <input type="hidden" name="faculty_id" id="faculty_id" value="<?= $faculty_id ?>">
            
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
                
                <!-- FACULTY (read-only) -->
                <div>
                    <label>Faculty</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($faculty_name) ?> (<?= htmlspecialchars($faculty_code) ?>)" readonly disabled>
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
    <?php endif; ?>
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php include('../includes/footer.php'); ?>

<script>
// Get current filename for AJAX calls
const currentFile = window.location.pathname.split('/').pop();
const facultyId = '<?= $faculty_id ?>';

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
    const deptSelect = document.getElementById('department_id');
    const programSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    const subjectSelect = document.getElementById('subject_id');
    const roomSelect = document.getElementById('room_id');
    
    if (!campusId) {
        resetHierarchy();
        return;
    }
    
    // Load departments for selected campus
    deptSelect.innerHTML = '<option value="">Loading...</option>';
    deptSelect.disabled = true;
    resetChildDropdowns(programSelect, classSelect, subjectSelect);
    
    fetch(`${currentFile}?ajax=get_departments&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            deptSelect.innerHTML = '<option value="">Select Department</option>';
            
            if (data.status === 'success' && data.departments.length > 0) {
                data.departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.display_name || dept.department_name;
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
    
    // Load rooms when campus is selected
    loadRooms(campusId, null, null);
}

function onDepartmentChange() {
    const deptId = document.getElementById('department_id').value;
    const campusId = document.getElementById('campus_id').value;
    const programSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    const subjectSelect = document.getElementById('subject_id');
    
    if (!deptId || !campusId) {
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
    loadRooms(campusIdForRooms, facultyId, deptId);
}

function onProgramChange() {
    const programId = document.getElementById('program_id').value;
    const deptId = document.getElementById('department_id').value;
    const campusId = document.getElementById('campus_id').value;
    const classSelect = document.getElementById('class_id');
    const subjectSelect = document.getElementById('subject_id');
    
    if (!programId || !deptId || !campusId) {
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
                    option.textContent = cls.display_name || cls.class_name;
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
    const campusId = document.getElementById('campus_id').value;
    const subjectSelect = document.getElementById('subject_id');
    
    if (!classId || !programId || !deptId || !campusId) {
        subjectSelect.innerHTML = '<option value="">Select Subject</option>';
        subjectSelect.disabled = true;
        return;
    }
    
    subjectSelect.innerHTML = '<option value="">Loading...</option>';
    subjectSelect.disabled = true;
    
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
    const deptId = data.department_id;
    const programId = data.program_id;
    const classId = data.class_id;
    const subjectId = data.subject_id;
    const teacherId = data.teacher_id;
    const roomId = data.room_id;
    
    // Load departments
    fetch(`${currentFile}?ajax=get_departments&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(deptData => {
            const deptSelect = document.getElementById('department_id');
            deptSelect.innerHTML = '<option value="">Select Department</option>';
            
            if (deptData.status === 'success' && deptData.departments.length > 0) {
                deptData.departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.display_name || dept.department_name;
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
                    option.textContent = cls.display_name || cls.class_name;
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
    const deptSelect = document.getElementById('department_id');
    const programSelect = document.getElementById('program_id');
    const classSelect = document.getElementById('class_id');
    const subjectSelect = document.getElementById('subject_id');
    const teacherSelect = document.getElementById('teacher_id');
    const roomSelect = document.getElementById('room_id');
    
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
    const deptSelect = document.getElementById('filter_department');
    const programSelect = document.getElementById('filter_program');
    const classSelect = document.getElementById('filter_class');
    
    if (!campusId) {
        deptSelect.disabled = true;
        programSelect.disabled = true;
        classSelect.disabled = true;
        return;
    }
    
    deptSelect.disabled = false;
    programSelect.disabled = true;
    classSelect.disabled = true;
    
    // Load departments for selected campus
    fetch(`${currentFile}?ajax=get_departments&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            deptSelect.innerHTML = '<option value="">All Departments</option>';
            if (data.status === 'success' && data.departments.length > 0) {
                data.departments.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.display_name || dept.department_name;
                    deptSelect.appendChild(option);
                });
            }
        });
}

function onFilterDepartmentChange() {
    const campusId = document.getElementById('filter_campus').value;
    const deptId = document.getElementById('filter_department').value;
    const programSelect = document.getElementById('filter_program');
    const classSelect = document.getElementById('filter_class');
    
    if (!deptId) {
        programSelect.disabled = true;
        classSelect.disabled = true;
        return;
    }
    
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
    const deptId = document.getElementById('filter_department').value;
    const programId = document.getElementById('filter_program').value;
    const classSelect = document.getElementById('filter_class');
    
    if (!programId) {
        classSelect.disabled = true;
        return;
    }
    
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
                    option.textContent = cls.display_name || cls.class_name;
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

// Initialize filter dropdowns
document.addEventListener('DOMContentLoaded', function() {
    // Load teachers when page loads
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