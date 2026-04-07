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
$message_type = "";

/* ===========================================
   AUTOMATIC STATUS UPDATES - OPTIMIZED
   =========================================== */
function runComprehensiveStatusUpdate($pdo) {
    try {
        $pdo->beginTransaction();
        $current_date = date('Y-m-d');
        
        // 1. UPDATE ACADEMIC YEAR STATUS
        $pdo->prepare("
            UPDATE academic_year 
            SET status = 'inactive', updated_at = NOW()
            WHERE end_date < ? AND status = 'active'
        ")->execute([$current_date]);
        
        $pdo->prepare("
            UPDATE academic_year 
            SET status = 'active', updated_at = NOW()
            WHERE start_date <= ? AND end_date >= ? AND status = 'inactive'
        ")->execute([$current_date, $current_date]);
        
        // 2. UPDATE ACADEMIC TERM STATUS
        $pdo->prepare("
            UPDATE academic_term 
            SET status = 'inactive', updated_at = NOW()
            WHERE end_date < ? AND status = 'active'
        ")->execute([$current_date]);
        
        $pdo->prepare("
            UPDATE academic_term 
            SET status = 'active', updated_at = NOW()
            WHERE start_date <= ? AND end_date >= ? 
            AND status = 'inactive'
            AND academic_year_id IN (
                SELECT academic_year_id FROM academic_year WHERE status = 'active'
            )
        ")->execute([$current_date, $current_date]);
        
        // 3. UPDATE TIMETABLE STATUS BASED ON TERM STATUS
        $pdo->prepare("
            UPDATE timetable t
            JOIN academic_term at ON t.academic_term_id = at.academic_term_id
            SET t.status = 'inactive', t.updated_at = NOW()
            WHERE at.status = 'inactive' AND t.status = 'active'
        ")->execute();
        
        $pdo->prepare("
            UPDATE timetable t
            JOIN academic_term at ON t.academic_term_id = at.academic_term_id
            SET t.status = 'active', t.updated_at = NOW()
            WHERE at.status = 'active' AND t.status = 'inactive'
        ")->execute();
        
        $pdo->commit();
        return true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Status update failed: " . $e->getMessage());
        return false;
    }
}

// Run status update
runComprehensiveStatusUpdate($pdo);

/* ===========================================
   AJAX HANDLERS - FIXED
   =========================================== */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    
    // GET ACTIVE ACADEMIC YEARS
    if ($_GET['ajax'] == 'get_academic_years') {
        try {
            $current_date = date('Y-m-d');
            
            $stmt = $pdo->prepare("
                SELECT academic_year_id, year_name, start_date, end_date, status 
                FROM academic_year 
                WHERE status = 'active'
                AND end_date >= ?
                ORDER BY start_date DESC
            ");
            $stmt->execute([$current_date]);
            $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if ($years) {
                echo json_encode(['success' => true, 'data' => $years]);
            } else {
                echo json_encode(['success' => false, 'message' => 'No active academic years found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // GET ACTIVE TERMS BY ACADEMIC YEAR
    if ($_GET['ajax'] == 'get_terms_by_year') {
        try {
            $year_id = filter_input(INPUT_GET, 'year_id', FILTER_VALIDATE_INT);
            
            if (!$year_id) {
                echo json_encode(['success' => false, 'message' => 'Academic Year ID required']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT academic_term_id, term_name, start_date, end_date, status 
                FROM academic_term 
                WHERE academic_year_id = ? 
                AND status = 'active'
                ORDER BY start_date ASC
            ");
            $stmt->execute([$year_id]);
            $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true, 
                'data' => $terms,
                'has_terms' => !empty($terms)
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // GET FACULTIES BY CAMPUS
    if ($_GET['ajax'] == 'get_faculties') {
        try {
            $campus_id = filter_input(INPUT_GET, 'campus_id', FILTER_VALIDATE_INT);
            
            if (!$campus_id) {
                echo json_encode(['success' => false, 'message' => 'Campus ID required']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT DISTINCT f.faculty_id, f.faculty_name 
                FROM faculties f
                JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
                WHERE fc.campus_id = ? AND f.status = 'active'
                ORDER BY f.faculty_name
            ");
            $stmt->execute([$campus_id]);
            $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $faculties]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // GET DEPARTMENTS BY FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_departments') {
        try {
            $faculty_id = filter_input(INPUT_GET, 'faculty_id', FILTER_VALIDATE_INT);
            $campus_id = filter_input(INPUT_GET, 'campus_id', FILTER_VALIDATE_INT);
            
            if (!$faculty_id || !$campus_id) {
                echo json_encode(['success' => false, 'message' => 'Faculty ID and Campus ID required']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT department_id, department_name 
                FROM departments 
                WHERE faculty_id = ? AND campus_id = ? AND status = 'active'
                ORDER BY department_name
            ");
            $stmt->execute([$faculty_id, $campus_id]);
            $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $departments]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // GET PROGRAMS BY DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_programs') {
        try {
            $department_id = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);
            $faculty_id = filter_input(INPUT_GET, 'faculty_id', FILTER_VALIDATE_INT);
            $campus_id = filter_input(INPUT_GET, 'campus_id', FILTER_VALIDATE_INT);
            
            if (!$department_id || !$faculty_id || !$campus_id) {
                echo json_encode(['success' => false, 'message' => 'Department, Faculty and Campus ID required']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT program_id, program_name, program_code 
                FROM programs 
                WHERE department_id = ? AND faculty_id = ? AND campus_id = ? AND status = 'active'
                ORDER BY program_name
            ");
            $stmt->execute([$department_id, $faculty_id, $campus_id]);
            $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $programs]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // GET CLASSES BY PROGRAM, DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_classes') {
        try {
            $program_id = filter_input(INPUT_GET, 'program_id', FILTER_VALIDATE_INT);
            $department_id = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);
            $faculty_id = filter_input(INPUT_GET, 'faculty_id', FILTER_VALIDATE_INT);
            $campus_id = filter_input(INPUT_GET, 'campus_id', FILTER_VALIDATE_INT);
            
            if (!$program_id || !$department_id || !$faculty_id || !$campus_id) {
                echo json_encode(['success' => false, 'message' => 'All IDs required']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT class_id, class_name 
                FROM classes 
                WHERE program_id = ? AND department_id = ? AND faculty_id = ? AND campus_id = ? 
                AND status = 'Active'
                ORDER BY class_name
            ");
            $stmt->execute([$program_id, $department_id, $faculty_id, $campus_id]);
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $classes]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // GET ACTIVE SUBJECTS BY CLASS, PROGRAM, DEPARTMENT, FACULTY & CAMPUS
    if ($_GET['ajax'] == 'get_subjects') {
        try {
            $class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
            $program_id = filter_input(INPUT_GET, 'program_id', FILTER_VALIDATE_INT);
            $department_id = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);
            $faculty_id = filter_input(INPUT_GET, 'faculty_id', FILTER_VALIDATE_INT);
            $campus_id = filter_input(INPUT_GET, 'campus_id', FILTER_VALIDATE_INT);
            
            if (!$class_id || !$program_id || !$department_id || !$faculty_id || !$campus_id) {
                echo json_encode(['success' => false, 'message' => 'All IDs required']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT subject_id, subject_name, subject_code 
                FROM subject 
                WHERE class_id = ? AND program_id = ? AND department_id = ? 
                AND faculty_id = ? AND campus_id = ? AND status = 'active'
                ORDER BY subject_name
            ");
            $stmt->execute([$class_id, $program_id, $department_id, $faculty_id, $campus_id]);
            $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $subjects]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // GET TEACHERS
    if ($_GET['ajax'] == 'get_teachers') {
        try {
            $stmt = $pdo->prepare("
                SELECT teacher_id, teacher_name 
                FROM teachers 
                WHERE status = 'active'
                ORDER BY teacher_name
            ");
            $stmt->execute();
            $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $teachers]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // GET ROOMS BY CAMPUS
    if ($_GET['ajax'] == 'get_rooms') {
        try {
            $campus_id = filter_input(INPUT_GET, 'campus_id', FILTER_VALIDATE_INT);
            $faculty_id = filter_input(INPUT_GET, 'faculty_id', FILTER_VALIDATE_INT);
            $department_id = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);
            
            if (!$campus_id) {
                echo json_encode(['success' => false, 'message' => 'Campus ID required']);
                exit;
            }
            
            $sql = "SELECT room_id, room_name, room_code, room_type, capacity 
                    FROM rooms WHERE campus_id = ? AND status = 'available'";
            $params = [$campus_id];
            
            if ($faculty_id) {
                $sql .= " AND (faculty_id = ? OR faculty_id IS NULL)";
                $params[] = $faculty_id;
            }
            
            if ($department_id) {
                $sql .= " AND (department_id = ? OR department_id IS NULL)";
                $params[] = $department_id;
            }
            
            $sql .= " ORDER BY room_name";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $rooms]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // CHECK TERM STATUS
    if ($_GET['ajax'] == 'check_term_status') {
        try {
            $term_id = filter_input(INPUT_GET, 'term_id', FILTER_VALIDATE_INT);
            
            if (!$term_id) {
                echo json_encode(['success' => false, 'message' => 'Term ID required']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT at.*, ay.year_name, ay.status as year_status
                FROM academic_term at
                JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
                WHERE at.academic_term_id = ?
            ");
            $stmt->execute([$term_id]);
            $term = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($term) {
                $current_date = date('Y-m-d');
                $is_expired = ($term['end_date'] < $current_date);
                
                echo json_encode([
                    'success' => true,
                    'data' => $term,
                    'is_expired' => $is_expired,
                    'current_date' => $current_date
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Term not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // CHECK SUBJECT STATUS
    if ($_GET['ajax'] == 'check_subject_status') {
        try {
            $subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);
            
            if (!$subject_id) {
                echo json_encode(['success' => false, 'message' => 'Subject ID required']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT subject_id, subject_name, subject_code, status
                FROM subject 
                WHERE subject_id = ?
            ");
            $stmt->execute([$subject_id]);
            $subject = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($subject) {
                echo json_encode([
                    'success' => true,
                    'data' => $subject,
                    'is_active' => ($subject['status'] === 'active')
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Subject not found']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

/* ===========================================
   CRUD OPERATIONS - FIXED
   =========================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $pdo->beginTransaction();
        
        function validateRequired($fields) {
            foreach ($fields as $label => $value) {
                if (empty($value) && $value !== '0') {
                    throw new Exception("⚠️ '$label' field is required.");
                }
            }
        }

        if ($action === 'add' || $action === 'update') {
            $id = $_POST['timetable_id'] ?? null;
            $campus_id = filter_input(INPUT_POST, 'campus_id', FILTER_VALIDATE_INT);
            $faculty_id = filter_input(INPUT_POST, 'faculty_id', FILTER_VALIDATE_INT);
            $department_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
            $program_id = filter_input(INPUT_POST, 'program_id', FILTER_VALIDATE_INT);
            $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
            $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
            $teacher_id = filter_input(INPUT_POST, 'teacher_id', FILTER_VALIDATE_INT);
            $room_id = filter_input(INPUT_POST, 'room_id', FILTER_VALIDATE_INT);
            $term_id = filter_input(INPUT_POST, 'academic_term_id', FILTER_VALIDATE_INT);
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

            if ($end <= $start) {
                throw new Exception("⛔ End time must be later than start time.");
            }
            
            // Check term validity
            $termStmt = $pdo->prepare("
                SELECT at.*, ay.status as year_status, ay.start_date as year_start, ay.end_date as year_end
                FROM academic_term at
                JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
                WHERE at.academic_term_id = ?
            ");
            $termStmt->execute([$term_id]);
            $term = $termStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$term) {
                throw new Exception("❌ Selected academic term not found.");
            }
            
            $current_date = date('Y-m-d');
            
            if ($term['year_status'] !== 'active') {
                throw new Exception("❌ Academic year is inactive.");
            }
            
            if ($term['status'] !== 'active') {
                throw new Exception("❌ Academic term is inactive.");
            }
            
            if ($term['end_date'] < $current_date) {
                $status = 'inactive';
            }
            
            // Check subject validity
            $subjectStmt = $pdo->prepare("
                SELECT subject_name, status 
                FROM subject 
                WHERE subject_id = ?
            ");
            $subjectStmt->execute([$subject_id]);
            $subject = $subjectStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$subject) {
                throw new Exception("❌ Selected subject not found.");
            }
            
            if ($subject['status'] !== 'active') {
                throw new Exception("❌ Selected subject '{$subject['subject_name']}' is inactive.");
            }
            
            // Check class validity
            $classStmt = $pdo->prepare("
                SELECT class_name, status 
                FROM classes 
                WHERE class_id = ?
            ");
            $classStmt->execute([$class_id]);
            $class = $classStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$class) {
                throw new Exception("❌ Selected class not found.");
            }
            
            if ($class['status'] !== 'Active') {
                throw new Exception("❌ Selected class '{$class['class_name']}' is inactive.");
            }

            // Conflict validation
            $conflictQuery = "
                SELECT timetable_id FROM timetable
                WHERE academic_term_id = ?
                AND day_of_week = ?
                AND timetable_id != COALESCE(?, 0)
                AND (
                    (class_id = ? AND campus_id = ? AND faculty_id = ? AND department_id = ? AND program_id = ?)
                    OR (teacher_id IS NOT NULL AND teacher_id = ? AND ? IS NOT NULL)
                    OR (room_id IS NOT NULL AND room_id = ? AND ? IS NOT NULL)
                )
                AND start_time < ? AND end_time > ?
            ";

            $conflictStmt = $pdo->prepare($conflictQuery);
            $conflictStmt->execute([
                $term_id, $day, $id,
                $class_id, $campus_id, $faculty_id, $department_id, $program_id,
                $teacher_id, $teacher_id,
                $room_id, $room_id,
                $end, $start
            ]);

            if ($conflictStmt->fetch()) {
                throw new Exception("❌ Time conflict! Class, teacher, or room already scheduled at this time.");
            }

            // Insert or Update
            if ($action === 'add') {
                $insertStmt = $pdo->prepare("
                    INSERT INTO timetable 
                    (campus_id, faculty_id, department_id, program_id, class_id, subject_id, 
                     teacher_id, room_id, academic_term_id, day_of_week, start_time, end_time, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $insertStmt->execute([
                    $campus_id, $faculty_id, $department_id, $program_id, $class_id, $subject_id,
                    $teacher_id ?: null, $room_id ?: null, $term_id, $day, $start, $end, $status
                ]);
                
                $message = "✅ Timetable entry added successfully!";
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE timetable 
                    SET campus_id = ?, faculty_id = ?, department_id = ?, program_id = ?, class_id = ?, 
                        subject_id = ?, teacher_id = ?, room_id = ?, academic_term_id = ?, 
                        day_of_week = ?, start_time = ?, end_time = ?, status = ?, updated_at = NOW()
                    WHERE timetable_id = ?
                ");
                
                $updateStmt->execute([
                    $campus_id, $faculty_id, $department_id, $program_id, $class_id, $subject_id,
                    $teacher_id ?: null, $room_id ?: null, $term_id, $day, $start, $end, $status, $id
                ]);
                
                $message = "✅ Timetable updated successfully!";
            }

            $message_type = "success";
        }

        elseif ($action === 'delete') {
            $id = filter_input(INPUT_POST, 'timetable_id', FILTER_VALIDATE_INT);
            
            if (!$id) {
                throw new Exception("Missing timetable ID.");
            }
            
            $deleteStmt = $pdo->prepare("DELETE FROM timetable WHERE timetable_id = ?");
            $deleteStmt->execute([$id]);
            
            $message = "✅ Timetable deleted successfully!";
            $message_type = "success";
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = $e->getMessage();
        $message_type = "error";
    }
}

/* ===========================================
   FETCH FILTER DATA - FIXED
   =========================================== */
$selectedCampus = $_GET['campus_id'] ?? ($role === 'campus_admin' ? $user_campus_id : null);
$selectedFaculty = $_GET['faculty_id'] ?? null;
$selectedDepartment = $_GET['department_id'] ?? null;
$selectedProgram = $_GET['program_id'] ?? null;
$selectedClass = $_GET['class_id'] ?? null;
$selectedTerm = $_GET['academic_term_id'] ?? null;
$selectedYear = $_GET['academic_year_id'] ?? null;

// Campuses
if ($role === 'super_admin') {
    $campuses = $pdo->query("
        SELECT campus_id, campus_name 
        FROM campus 
        WHERE status = 'active' 
        ORDER BY campus_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $campusStmt = $pdo->prepare("
        SELECT campus_id, campus_name 
        FROM campus 
        WHERE campus_id = ? AND status = 'active'
    ");
    $campusStmt->execute([$user_campus_id]);
    $campuses = $campusStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Academic Years
$current_date = date('Y-m-d');
$academicYearsStmt = $pdo->prepare("
    SELECT academic_year_id, year_name, start_date, end_date, status 
    FROM academic_year 
    WHERE status = 'active' AND end_date >= ?
    ORDER BY start_date DESC
");
$academicYearsStmt->execute([$current_date]);
$academicYears = $academicYearsStmt->fetchAll(PDO::FETCH_ASSOC);

// Filter Faculties
$filterFaculties = [];
if ($selectedCampus) {
    $facultyStmt = $pdo->prepare("
        SELECT DISTINCT f.faculty_id, f.faculty_name
        FROM faculties f
        JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
        WHERE fc.campus_id = ? AND f.status = 'active'
        ORDER BY f.faculty_name ASC
    ");
    $facultyStmt->execute([$selectedCampus]);
    $filterFaculties = $facultyStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Filter Departments
$filterDepartments = [];
if ($selectedFaculty && $selectedCampus) {
    $deptStmt = $pdo->prepare("
        SELECT department_id, department_name
        FROM departments
        WHERE faculty_id = ? AND campus_id = ? AND status = 'active'
        ORDER BY department_name ASC
    ");
    $deptStmt->execute([$selectedFaculty, $selectedCampus]);
    $filterDepartments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Filter Programs
$filterPrograms = [];
if ($selectedDepartment && $selectedFaculty && $selectedCampus) {
    $programStmt = $pdo->prepare("
        SELECT program_id, program_name, program_code
        FROM programs
        WHERE department_id = ? AND faculty_id = ? AND campus_id = ? AND status = 'active'
        ORDER BY program_name ASC
    ");
    $programStmt->execute([$selectedDepartment, $selectedFaculty, $selectedCampus]);
    $filterPrograms = $programStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Filter Classes
$filterClasses = [];
if ($selectedProgram && $selectedDepartment && $selectedFaculty && $selectedCampus) {
    $classStmt = $pdo->prepare("
        SELECT class_id, class_name
        FROM classes
        WHERE program_id = ? AND department_id = ? AND faculty_id = ? AND campus_id = ? 
        AND status = 'Active'
        ORDER BY class_name ASC
    ");
    $classStmt->execute([$selectedProgram, $selectedDepartment, $selectedFaculty, $selectedCampus]);
    $filterClasses = $classStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Terms for filter
$termsQuery = "
    SELECT at.*, ay.year_name,
           CASE 
               WHEN at.end_date < ? THEN 'expired'
               ELSE at.status 
           END as display_status
    FROM academic_term at
    JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
    WHERE at.status = 'active' AND ay.status = 'active'
";
$termsParams = [$current_date];

if ($selectedYear) {
    $termsQuery .= " AND at.academic_year_id = ?";
    $termsParams[] = $selectedYear;
}

$termsQuery .= " ORDER BY at.start_date DESC";

$termsStmt = $pdo->prepare($termsQuery);
$termsStmt->execute($termsParams);
$terms = $termsStmt->fetchAll(PDO::FETCH_ASSOC);

/* ===========================================
   FETCH TIMETABLE DATA - FIXED
   =========================================== */
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
           r.room_name, r.room_code,
           tr.teacher_name,
           at.term_name,
           at.start_date as term_start,
           at.end_date as term_end,
           at.status as term_status,
           ay.year_name,
           CASE 
               WHEN at.end_date < CURDATE() THEN 'expired'
               ELSE at.status 
           END as term_display_status
    FROM timetable t
    JOIN classes c ON t.class_id = c.class_id 
    JOIN campus ca ON t.campus_id = ca.campus_id
    JOIN faculties f ON t.faculty_id = f.faculty_id
    JOIN departments d ON t.department_id = d.department_id
    JOIN programs p ON t.program_id = p.program_id
    JOIN subject subj ON t.subject_id = subj.subject_id
    LEFT JOIN teachers tr ON t.teacher_id = tr.teacher_id
    LEFT JOIN rooms r ON t.room_id = r.room_id
    JOIN academic_term at ON t.academic_term_id = at.academic_term_id
    JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
    $whereClause
    ORDER BY FIELD(t.day_of_week, 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'),
             t.start_time
";

if ($whereConditions) {
    $timetablesStmt = $pdo->prepare($timetablesQuery);
    $timetablesStmt->execute($params);
    $timetables = $timetablesStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $timetables = $pdo->query($timetablesQuery)->fetchAll(PDO::FETCH_ASSOC);
}

$daysOrder = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

$timetableByDay = [];
foreach ($timetables as $schedule) {
    $day = $schedule['day_of_week'];
    if (!isset($timetableByDay[$day])) {
        $timetableByDay[$day] = [];
    }
    $timetableByDay[$day][] = $schedule;
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
    --expired-color: #9E9E9E;
}
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}
body {
    font-family: 'Poppins', sans-serif;
    background: var(--bg);
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
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}
.page-header h1 {
    color: var(--blue);
    font-size: 24px;
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
    display: flex;
    align-items: center;
    gap: 8px;
}
.add-btn:hover {
    background: var(--light-green);
    transform: translateY(-2px);
}
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
select, input {
    width: 100%;
    padding: 8px 12px;
    border: 1.5px solid #ddd;
    border-radius: 6px;
    font-size: 13.5px;
    background: #f9f9f9;
    transition: 0.2s;
}
select:focus, input:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px rgba(0,114,206,0.12);
    outline: none;
    background: #fff;
}
select:disabled {
    background: #e9ecef;
    cursor: not-allowed;
}
.btn {
    border: none;
    padding: 10px 18px;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    transition: .3s;
    display: flex;
    align-items: center;
    gap: 8px;
}
.btn.blue {
    background: var(--blue);
    color: #fff;
}
.btn.blue:hover {
    background: #0056b3;
}
.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    display: inline-block;
}
.status-active {
    background: #e8f5e9;
    color: var(--green);
}
.status-inactive {
    background: #ffebee;
    color: var(--red);
}
.status-expired {
    background: #f5f5f5;
    color: var(--expired-color);
}
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
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.tabs {
    display: flex;
    background: #f0f7ff;
    border-bottom: 1px solid #ddd;
    overflow-x: auto;
}
.tab {
    padding: 12px 25px;
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
.table-container {
    max-height: 600px;
    overflow-y: auto;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
}
.data-table {
    width: 100%;
    border-collapse: collapse;
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
.actions {
    display: flex;
    gap: 8px;
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
}
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
    max-width: 800px;
    padding: 30px;
    position: relative;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
    border-top: 5px solid var(--blue);
    max-height: 90vh;
    overflow-y: auto;
}
.close-modal {
    position: absolute;
    top: 15px;
    right: 20px;
    font-size: 28px;
    cursor: pointer;
    color: var(--red);
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
}
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}
.form-full {
    grid-column: 1 / -1;
}
.required::after {
    content: " *";
    color: var(--red);
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
.term-warning, .subject-warning {
    background: #fff3cd;
    color: #856404;
    padding: 10px;
    border-radius: 6px;
    border: 1px solid #ffeaa7;
    margin: 10px 0;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 8px;
    grid-column: 1 / -1;
}
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
}
.alert.error {
    background: var(--red);
}
@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding: 15px;
    }
    .form-grid {
        grid-template-columns: 1fr;
    }
    .table-container {
        overflow-x: auto;
    }
    .data-table {
        min-width: 800px;
    }
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-calendar-alt" style="margin-right: 10px;"></i>Class Timetable</h1>
        <button class="add-btn" onclick="openModal()">
            <i class="fas fa-plus"></i> Add Schedule
        </button>
    </div>

    <!-- Filter Section -->
    <div class="filter-box">
        <h3 style="color: var(--blue); margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-filter"></i> Filter Timetable
        </h3>
        <form method="GET" class="grid">
            <div>
                <label for="filter_campus">Campus</label>
                <select name="campus_id" id="filter_campus" onchange="filterCampusChange()">
                    <option value="">All Campuses</option>
                    <?php foreach($campuses as $campus): ?>
                    <option value="<?= $campus['campus_id'] ?>" 
                        <?= $selectedCampus == $campus['campus_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($campus['campus_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label for="filter_academic_year">Academic Year</label>
                <select name="academic_year_id" id="filter_academic_year" onchange="filterYearChange()">
                    <option value="">All Years</option>
                    <?php foreach($academicYears as $year): ?>
                    <option value="<?= $year['academic_year_id'] ?>" 
                        <?= $selectedYear == $year['academic_year_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($year['year_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label for="filter_faculty">Faculty</label>
                <select name="faculty_id" id="filter_faculty" onchange="filterFacultyChange()" 
                    <?= !$selectedCampus ? 'disabled' : '' ?>>
                    <option value="">All Faculties</option>
                    <?php foreach($filterFaculties as $faculty): ?>
                    <option value="<?= $faculty['faculty_id'] ?>" 
                        <?= $selectedFaculty == $faculty['faculty_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($faculty['faculty_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label for="filter_department">Department</label>
                <select name="department_id" id="filter_department" onchange="filterDepartmentChange()"
                    <?= !$selectedFaculty ? 'disabled' : '' ?>>
                    <option value="">All Departments</option>
                    <?php foreach($filterDepartments as $dept): ?>
                    <option value="<?= $dept['department_id'] ?>" 
                        <?= $selectedDepartment == $dept['department_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dept['department_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label for="filter_program">Program</label>
                <select name="program_id" id="filter_program" onchange="filterProgramChange()"
                    <?= !$selectedDepartment ? 'disabled' : '' ?>>
                    <option value="">All Programs</option>
                    <?php foreach($filterPrograms as $program): ?>
                    <option value="<?= $program['program_id'] ?>" 
                        <?= $selectedProgram == $program['program_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($program['program_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label for="filter_class">Class</label>
                <select name="class_id" id="filter_class" 
                    <?= !$selectedProgram ? 'disabled' : '' ?>>
                    <option value="">All Classes</option>
                    <?php foreach($filterClasses as $class): ?>
                    <option value="<?= $class['class_id'] ?>" 
                        <?= $selectedClass == $class['class_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($class['class_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label for="filter_term">Academic Term</label>
                <select name="academic_term_id" id="filter_term">
                    <option value="">All Terms</option>
                    <?php foreach($terms as $term): ?>
                    <option value="<?= $term['academic_term_id'] ?>" 
                        <?= $selectedTerm == $term['academic_term_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($term['term_name']) ?> (<?= $term['display_status'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="display: flex; gap: 10px; align-items: flex-end;">
                <button type="submit" class="btn blue">
                    <i class="fas fa-search"></i> Apply
                </button>
                <button type="button" class="btn" onclick="clearFilters()" style="background: #6c757d; color: white;">
                    <i class="fas fa-times"></i> Clear
                </button>
            </div>
        </form>
    </div>

    <!-- Timetable Tabs -->
    <div class="timetable-container">
        <div class="timetable-header">
            <span><i class="fas fa-calendar-week"></i> Weekly Schedule</span>
            <span style="font-size: 14px;">Total: <?= count($timetables) ?> schedules</span>
        </div>
        
        <div class="tabs" id="dayTabs">
            <?php foreach($daysOrder as $index => $day): ?>
            <button class="tab <?= $index === 0 ? 'active' : '' ?>" onclick="switchTab('<?= $day ?>', this)">
                <?= $day ?>
                <?php if(isset($timetableByDay[$day])): ?>
                <span style="background: var(--blue); color: white; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px;">
                    <?= count($timetableByDay[$day]) ?>
                </span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>
        
        <?php foreach($daysOrder as $index => $day): ?>
        <div class="tab-content <?= $index === 0 ? 'active' : '' ?>" id="tab-<?= $day ?>">
            <?php if(isset($timetableByDay[$day]) && !empty($timetableByDay[$day])): ?>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Subject</th>
                            <th>Class</th>
                            <th>Teacher</th>
                            <th>Room</th>
                            <th>Term</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($timetableByDay[$day] as $schedule): 
                            $term_expired = ($schedule['term_end'] < date('Y-m-d'));
                            $status_class = $term_expired ? 'expired' : $schedule['status'];
                        ?>
                        <tr>
                            <td>
                                <strong><?= date('h:i A', strtotime($schedule['start_time'])) ?></strong> - 
                                <strong><?= date('h:i A', strtotime($schedule['end_time'])) ?></strong>
                            </td>
                            <td>
                                <?= htmlspecialchars($schedule['subject_name']) ?>
                                <div style="font-size: 11px; color: #666;">
                                    <?= htmlspecialchars($schedule['subject_code']) ?>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($schedule['class_name']) ?></td>
                            <td><?= htmlspecialchars($schedule['teacher_name'] ?? 'N/A') ?></td>
                            <td>
                                <?php if($schedule['room_name']): ?>
                                    <?= htmlspecialchars($schedule['room_name']) ?>
                                    <?php if($schedule['room_code']): ?>
                                        (<?= htmlspecialchars($schedule['room_code']) ?>)
                                    <?php endif; ?>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($schedule['term_name']) ?>
                                <div style="font-size: 11px; color: #666;">
                                    <?= date('M d', strtotime($schedule['term_start'])) ?> - 
                                    <?= date('M d', strtotime($schedule['term_end'])) ?>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge status-<?= $status_class ?>">
                                    <?= ucfirst($status_class) ?>
                                </span>
                            </td>
                            <td class="actions">
                                <button class="action-btn edit-btn" onclick="editSchedule(<?= htmlspecialchars(json_encode($schedule)) ?>)">
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
                <p>Click "Add Schedule" to create a new schedule.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal -->
<div class="modal" id="scheduleModal">
    <div class="modal-content">
        <button class="close-modal" onclick="closeModal()">&times;</button>
        <h2 style="color: var(--blue); margin-bottom: 20px;" id="modalTitle">Add New Schedule</h2>
        
        <form method="POST" id="timetableForm" onsubmit="return validateForm()">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="timetable_id" id="timetable_id">
            
            <div class="form-grid">
                <div>
                    <label for="campus_id" class="required">Campus</label>
                    <select name="campus_id" id="campus_id" required onchange="loadFaculties()">
                        <option value="">Select Campus</option>
                        <?php foreach($campuses as $campus): ?>
                        <option value="<?= $campus['campus_id'] ?>">
                            <?= htmlspecialchars($campus['campus_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="academic_year_id" class="required">Academic Year</label>
                    <select name="academic_year_id" id="academic_year_id" required onchange="loadTerms()">
                        <option value="">Select Academic Year</option>
                    </select>
                </div>
                
                <div>
                    <label for="faculty_id" class="required">Faculty</label>
                    <select name="faculty_id" id="faculty_id" required onchange="loadDepartments()" disabled>
                        <option value="">Select Faculty</option>
                    </select>
                </div>
                
                <div>
                    <label for="department_id" class="required">Department</label>
                    <select name="department_id" id="department_id" required onchange="loadPrograms()" disabled>
                        <option value="">Select Department</option>
                    </select>
                </div>
                
                <div>
                    <label for="program_id" class="required">Program</label>
                    <select name="program_id" id="program_id" required onchange="loadClasses()" disabled>
                        <option value="">Select Program</option>
                    </select>
                </div>
                
                <div>
                    <label for="class_id" class="required">Class</label>
                    <select name="class_id" id="class_id" required onchange="loadSubjects()" disabled>
                        <option value="">Select Class</option>
                    </select>
                </div>
                
                <div>
                    <label for="subject_id" class="required">Subject</label>
                    <select name="subject_id" id="subject_id" required onchange="checkSubjectStatus()" disabled>
                        <option value="">Select Subject</option>
                    </select>
                </div>
                
                <div>
                    <label for="academic_term_id" class="required">Academic Term</label>
                    <select name="academic_term_id" id="academic_term_id" required onchange="checkTermStatus()" disabled>
                        <option value="">Select Term</option>
                    </select>
                </div>
                
                <div>
                    <label for="day_of_week" class="required">Day of Week</label>
                    <select name="day_of_week" id="day_of_week" required>
                        <option value="">Select Day</option>
                        <?php foreach($daysOrder as $day): ?>
                        <option value="<?= $day ?>"><?= $day ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="start_time" class="required">Start Time</label>
                    <input type="time" name="start_time" id="start_time" required>
                </div>
                
                <div>
                    <label for="end_time" class="required">End Time</label>
                    <input type="time" name="end_time" id="end_time" required>
                </div>
                
                <div>
                    <label for="teacher_id">Teacher</label>
                    <select name="teacher_id" id="teacher_id">
                        <option value="">Select Teacher</option>
                    </select>
                </div>
                
                <div>
                    <label for="room_id">Room</label>
                    <select name="room_id" id="room_id">
                        <option value="">Select Room</option>
                    </select>
                </div>
                
                <div>
                    <label for="status" class="required">Status</label>
                    <select name="status" id="status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            
            <div id="termWarning" class="term-warning" style="display: none;"></div>
            <div id="subjectWarning" class="term-warning" style="display: none;"></div>
            
            <button type="submit" class="save-btn">
                <i class="fas fa-save"></i> Save Schedule
            </button>
        </form>
    </div>
</div>

<?php if($message): ?>
<div class="alert <?= $message_type ?>" id="alertMessage">
    <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php include('../includes/footer.php'); ?>

<script>
const currentFile = window.location.pathname.split('/').pop();

// ===========================================
// FILTER FUNCTIONS
// ===========================================
function filterCampusChange() {
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
    
    facultySelect.disabled = false;
    facultySelect.innerHTML = '<option value="">All Faculties</option>';
    
    fetch(`${currentFile}?ajax=get_faculties&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                data.data.forEach(faculty => {
                    const option = document.createElement('option');
                    option.value = faculty.faculty_id;
                    option.textContent = faculty.faculty_name;
                    facultySelect.appendChild(option);
                });
            }
        });
}

function filterFacultyChange() {
    const facultyId = document.getElementById('filter_faculty').value;
    const campusId = document.getElementById('filter_campus').value;
    const deptSelect = document.getElementById('filter_department');
    const programSelect = document.getElementById('filter_program');
    const classSelect = document.getElementById('filter_class');
    
    if (!facultyId || !campusId) {
        deptSelect.disabled = true;
        programSelect.disabled = true;
        classSelect.disabled = true;
        return;
    }
    
    deptSelect.disabled = false;
    deptSelect.innerHTML = '<option value="">All Departments</option>';
    
    fetch(`${currentFile}?ajax=get_departments&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                data.data.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.department_name;
                    deptSelect.appendChild(option);
                });
            }
        });
}

function filterDepartmentChange() {
    const deptId = document.getElementById('filter_department').value;
    const facultyId = document.getElementById('filter_faculty').value;
    const campusId = document.getElementById('filter_campus').value;
    const programSelect = document.getElementById('filter_program');
    const classSelect = document.getElementById('filter_class');
    
    if (!deptId || !facultyId || !campusId) {
        programSelect.disabled = true;
        classSelect.disabled = true;
        return;
    }
    
    programSelect.disabled = false;
    programSelect.innerHTML = '<option value="">All Programs</option>';
    
    fetch(`${currentFile}?ajax=get_programs&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                data.data.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = `${program.program_name} (${program.program_code})`;
                    programSelect.appendChild(option);
                });
            }
        });
}

function filterProgramChange() {
    const programId = document.getElementById('filter_program').value;
    const deptId = document.getElementById('filter_department').value;
    const facultyId = document.getElementById('filter_faculty').value;
    const campusId = document.getElementById('filter_campus').value;
    const classSelect = document.getElementById('filter_class');
    
    if (!programId || !deptId || !facultyId || !campusId) {
        classSelect.disabled = true;
        return;
    }
    
    classSelect.disabled = false;
    classSelect.innerHTML = '<option value="">All Classes</option>';
    
    fetch(`${currentFile}?ajax=get_classes&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                data.data.forEach(cls => {
                    const option = document.createElement('option');
                    option.value = cls.class_id;
                    option.textContent = cls.class_name;
                    classSelect.appendChild(option);
                });
            }
        });
}

function filterYearChange() {
    const yearId = document.getElementById('filter_academic_year').value;
    const termSelect = document.getElementById('filter_term');
    
    if (!yearId) {
        // Reload all terms
        window.location.reload();
        return;
    }
    
    fetch(`${currentFile}?ajax=get_terms_by_year&year_id=${yearId}`)
        .then(response => response.json())
        .then(data => {
            termSelect.innerHTML = '<option value="">All Terms</option>';
            if (data.success && data.data.length > 0) {
                data.data.forEach(term => {
                    const option = document.createElement('option');
                    option.value = term.academic_term_id;
                    option.textContent = `${term.term_name} (${term.status})`;
                    termSelect.appendChild(option);
                });
            }
        });
}

// ===========================================
// MODAL FUNCTIONS
// ===========================================
function openModal() {
    document.getElementById('scheduleModal').classList.add('show');
    document.getElementById('modalTitle').innerText = 'Add New Schedule';
    document.getElementById('formAction').value = 'add';
    document.getElementById('timetableForm').reset();
    document.getElementById('timetable_id').value = '';
    
    resetModalDropdowns();
    loadAcademicYears();
    loadTeachers();
    loadRooms();
}

function closeModal() {
    document.getElementById('scheduleModal').classList.remove('show');
}

function resetModalDropdowns() {
    const selects = ['faculty_id', 'department_id', 'program_id', 'class_id', 
                    'subject_id', 'academic_term_id'];
    
    selects.forEach(id => {
        const select = document.getElementById(id);
        select.innerHTML = `<option value="">Select ${id.replace('_id', '').replace('_', ' ')}</option>`;
        select.disabled = true;
    });
}

function loadAcademicYears() {
    const select = document.getElementById('academic_year_id');
    select.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`${currentFile}?ajax=get_academic_years`)
        .then(response => response.json())
        .then(data => {
            select.innerHTML = '<option value="">Select Academic Year</option>';
            if (data.success && data.data.length > 0) {
                data.data.forEach(year => {
                    const option = document.createElement('option');
                    option.value = year.academic_year_id;
                    option.textContent = year.year_name;
                    select.appendChild(option);
                });
            } else {
                select.innerHTML = '<option value="">No active academic years</option>';
            }
        });
}

function loadFaculties() {
    const campusId = document.getElementById('campus_id').value;
    const select = document.getElementById('faculty_id');
    
    if (!campusId) {
        select.disabled = true;
        select.innerHTML = '<option value="">Select Faculty</option>';
        return;
    }
    
    select.disabled = false;
    select.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`${currentFile}?ajax=get_faculties&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            select.innerHTML = '<option value="">Select Faculty</option>';
            if (data.success && data.data.length > 0) {
                data.data.forEach(faculty => {
                    const option = document.createElement('option');
                    option.value = faculty.faculty_id;
                    option.textContent = faculty.faculty_name;
                    select.appendChild(option);
                });
            } else {
                select.innerHTML = '<option value="">No faculties found</option>';
            }
        });
    
    // Also load rooms for this campus
    loadRooms(campusId);
}

function loadDepartments() {
    const facultyId = document.getElementById('faculty_id').value;
    const campusId = document.getElementById('campus_id').value;
    const select = document.getElementById('department_id');
    
    if (!facultyId || !campusId) {
        select.disabled = true;
        select.innerHTML = '<option value="">Select Department</option>';
        return;
    }
    
    select.disabled = false;
    select.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`${currentFile}?ajax=get_departments&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            select.innerHTML = '<option value="">Select Department</option>';
            if (data.success && data.data.length > 0) {
                data.data.forEach(dept => {
                    const option = document.createElement('option');
                    option.value = dept.department_id;
                    option.textContent = dept.department_name;
                    select.appendChild(option);
                });
            } else {
                select.innerHTML = '<option value="">No departments found</option>';
            }
        });
}

function loadPrograms() {
    const deptId = document.getElementById('department_id').value;
    const facultyId = document.getElementById('faculty_id').value;
    const campusId = document.getElementById('campus_id').value;
    const select = document.getElementById('program_id');
    
    if (!deptId || !facultyId || !campusId) {
        select.disabled = true;
        select.innerHTML = '<option value="">Select Program</option>';
        return;
    }
    
    select.disabled = false;
    select.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`${currentFile}?ajax=get_programs&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            select.innerHTML = '<option value="">Select Program</option>';
            if (data.success && data.data.length > 0) {
                data.data.forEach(program => {
                    const option = document.createElement('option');
                    option.value = program.program_id;
                    option.textContent = `${program.program_name} (${program.program_code})`;
                    select.appendChild(option);
                });
            } else {
                select.innerHTML = '<option value="">No programs found</option>';
            }
        });
}

function loadClasses() {
    const programId = document.getElementById('program_id').value;
    const deptId = document.getElementById('department_id').value;
    const facultyId = document.getElementById('faculty_id').value;
    const campusId = document.getElementById('campus_id').value;
    const select = document.getElementById('class_id');
    
    if (!programId || !deptId || !facultyId || !campusId) {
        select.disabled = true;
        select.innerHTML = '<option value="">Select Class</option>';
        return;
    }
    
    select.disabled = false;
    select.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`${currentFile}?ajax=get_classes&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            select.innerHTML = '<option value="">Select Class</option>';
            if (data.success && data.data.length > 0) {
                data.data.forEach(cls => {
                    const option = document.createElement('option');
                    option.value = cls.class_id;
                    option.textContent = cls.class_name;
                    select.appendChild(option);
                });
            } else {
                select.innerHTML = '<option value="">No classes found</option>';
            }
        });
}

function loadSubjects() {
    const classId = document.getElementById('class_id').value;
    const programId = document.getElementById('program_id').value;
    const deptId = document.getElementById('department_id').value;
    const facultyId = document.getElementById('faculty_id').value;
    const campusId = document.getElementById('campus_id').value;
    const select = document.getElementById('subject_id');
    
    if (!classId || !programId || !deptId || !facultyId || !campusId) {
        select.disabled = true;
        select.innerHTML = '<option value="">Select Subject</option>';
        return;
    }
    
    select.disabled = false;
    select.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`${currentFile}?ajax=get_subjects&class_id=${classId}&program_id=${programId}&department_id=${deptId}&faculty_id=${facultyId}&campus_id=${campusId}`)
        .then(response => response.json())
        .then(data => {
            select.innerHTML = '<option value="">Select Subject</option>';
            if (data.success && data.data.length > 0) {
                data.data.forEach(subject => {
                    const option = document.createElement('option');
                    option.value = subject.subject_id;
                    option.textContent = `${subject.subject_name} (${subject.subject_code})`;
                    select.appendChild(option);
                });
            } else {
                select.innerHTML = '<option value="">No subjects found</option>';
            }
        });
}

function loadTerms() {
    const yearId = document.getElementById('academic_year_id').value;
    const select = document.getElementById('academic_term_id');
    
    if (!yearId) {
        select.disabled = true;
        select.innerHTML = '<option value="">Select Term</option>';
        return;
    }
    
    select.disabled = false;
    select.innerHTML = '<option value="">Loading...</option>';
    
    fetch(`${currentFile}?ajax=get_terms_by_year&year_id=${yearId}`)
        .then(response => response.json())
        .then(data => {
            select.innerHTML = '<option value="">Select Term</option>';
            if (data.success && data.data.length > 0) {
                data.data.forEach(term => {
                    const option = document.createElement('option');
                    option.value = term.academic_term_id;
                    option.textContent = `${term.term_name} (${term.status})`;
                    select.appendChild(option);
                });
            } else {
                select.innerHTML = '<option value="">No terms found</option>';
            }
        });
}

function loadTeachers() {
    const select = document.getElementById('teacher_id');
    
    fetch(`${currentFile}?ajax=get_teachers`)
        .then(response => response.json())
        .then(data => {
            select.innerHTML = '<option value="">Select Teacher</option>';            
            if (data.success && data.data.length > 0) {
                data.data.forEach(teacher => {
                    const option = document.createElement('option');
                    option.value = teacher.teacher_id;
                    option.textContent = teacher.teacher_name;
                    select.appendChild(option);
                });
            }
        });
}

function loadRooms(campusId = null) {
    const select = document.getElementById('room_id');
    const campus = campusId || document.getElementById('campus_id').value;
    const facultyId = document.getElementById('faculty_id').value;
    const deptId = document.getElementById('department_id').value;
    
    if (!campus) {
        select.innerHTML = '<option value="">Select Room</option>';
        return;
    }
    
    let url = `${currentFile}?ajax=get_rooms&campus_id=${campus}`;
    if (facultyId) url += `&faculty_id=${facultyId}`;
    if (deptId) url += `&department_id=${deptId}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            select.innerHTML = '<option value="">Select Room</option>';            
            if (data.success && data.data.length > 0) {
                data.data.forEach(room => {
                    const option = document.createElement('option');
                    option.value = room.room_id;
                    option.textContent = `${room.room_name} (${room.room_code}) - ${room.capacity} seats`;
                    select.appendChild(option);
                });
            }
        });
}

function checkTermStatus() {
    const termId = document.getElementById('academic_term_id').value;
    const warning = document.getElementById('termWarning');
    const statusSelect = document.getElementById('status');
    
    if (!termId) {
        warning.style.display = 'none';
        return;
    }
    
    fetch(`${currentFile}?ajax=check_term_status&term_id=${termId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.is_expired || data.data.status !== 'active' || data.data.year_status !== 'active') {
                    warning.style.display = 'flex';
                    warning.innerHTML = `<i class="fas fa-exclamation-triangle"></i>
                        <span>This term is ${data.is_expired ? 'expired' : 'inactive'}. 
                        Schedule will be created as inactive.</span>`;
                    statusSelect.value = 'inactive';
                } else {
                    warning.style.display = 'none';
                }
            }
        });
}

function checkSubjectStatus() {
    const subjectId = document.getElementById('subject_id').value;
    const warning = document.getElementById('subjectWarning');
    
    if (!subjectId) {
        warning.style.display = 'none';
        return;
    }
    
    fetch(`${currentFile}?ajax=check_subject_status&subject_id=${subjectId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && !data.is_active) {
                warning.style.display = 'flex';
                warning.innerHTML = `<i class="fas fa-exclamation-triangle"></i>
                    <span>Selected subject is inactive. It may not appear in active timetables.</span>`;
            } else {
                warning.style.display = 'none';
            }
        });
}

function editSchedule(data) {
    openModal();
    document.getElementById('modalTitle').innerText = 'Edit Schedule';
    document.getElementById('formAction').value = 'update';
    document.getElementById('timetable_id').value = data.timetable_id;
    
    // Set basic fields
    document.getElementById('campus_id').value = data.campus_id;
    document.getElementById('day_of_week').value = data.day_of_week;
    document.getElementById('start_time').value = data.start_time.substring(0, 5);
    document.getElementById('end_time').value = data.end_time.substring(0, 5);
    document.getElementById('status').value = data.status;
    
    // Load hierarchy
    loadFaculties();
    
    // Set values after loading
    setTimeout(() => {
        document.getElementById('faculty_id').value = data.faculty_id;
        loadDepartments();
        
        setTimeout(() => {
            document.getElementById('department_id').value = data.department_id;
            loadPrograms();
            
            setTimeout(() => {
                document.getElementById('program_id').value = data.program_id;
                loadClasses();
                
                setTimeout(() => {
                    document.getElementById('class_id').value = data.class_id;
                    loadSubjects();
                    
                    setTimeout(() => {
                        document.getElementById('subject_id').value = data.subject_id;
                    }, 300);
                }, 300);
            }, 300);
        }, 300);
    }, 300);
    
    // Load academic year and term
    loadAcademicYears();
    setTimeout(() => {
        // We need to get the academic year from the term
        fetch(`${currentFile}?ajax=check_term_status&term_id=${data.academic_term_id}`)
            .then(response => response.json())
            .then(termData => {
                if (termData.success) {
                    // Find the academic year select option
                    const yearSelect = document.getElementById('academic_year_id');
                    for (let option of yearSelect.options) {
                        if (option.text.includes(termData.data.year_name)) {
                            yearSelect.value = option.value;
                            break;
                        }
                    }
                    loadTerms();
                    
                    setTimeout(() => {
                        document.getElementById('academic_term_id').value = data.academic_term_id;
                        checkTermStatus();
                    }, 300);
                }
            });
    }, 300);
    
    // Load teacher and room
    if (data.teacher_id) {
        setTimeout(() => {
            document.getElementById('teacher_id').value = data.teacher_id;
        }, 500);
    }
    
    loadRooms();
    setTimeout(() => {
        if (data.room_id) {
            document.getElementById('room_id').value = data.room_id;
        }
    }, 500);
}

function validateForm() {
    const start = document.getElementById('start_time').value;
    const end = document.getElementById('end_time').value;
    
    if (!start || !end) {
        alert('Please enter both start and end times.');
        return false;
    }
    
    if (end <= start) {
        alert('End time must be later than start time.');
        return false;
    }
    
    return true;
}

function switchTab(day, element) {
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    document.getElementById('tab-' + day).classList.add('active');
    element.classList.add('active');
}

function clearFilters() {
    window.location.href = window.location.pathname;
}

function confirmDelete() {
    return confirm('Are you sure you want to delete this schedule? This action cannot be undone.');
}

// Auto-hide alert
if (document.getElementById('alertMessage')) {
    setTimeout(() => {
        const alert = document.getElementById('alertMessage');
        alert.style.opacity = '0';
        setTimeout(() => {
            if (alert.parentNode) alert.remove();
        }, 500);
    }, 5000);
}

// Close modal on ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeModal();
    }
});

// Close modal on outside click
window.onclick = function(e) {
    const modal = document.getElementById('scheduleModal');
    if (e.target === modal) {
        closeModal();
    }
}
</script>
</body>
</html>
<?php ob_end_flush(); ?>