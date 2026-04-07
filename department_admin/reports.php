<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// Check if user is logged in and has department_admin role
if (!isset($_SESSION['user']) || strtolower($_SESSION['user']['role'] ?? '') !== 'department_admin') {
    header("Location: ../login.php");
    exit;
}

date_default_timezone_set('Africa/Nairobi');

// Get current user info
$current_user = $_SESSION['user'];
$department_id = $current_user['linked_id']; // This links to departments table

// Verify department exists and get department details
$dept_stmt = $pdo->prepare("
    SELECT d.*, f.faculty_name, c.campus_name, c.campus_code
    FROM departments d
    LEFT JOIN faculties f ON f.faculty_id = d.faculty_id
    LEFT JOIN campus c ON c.campus_id = d.campus_id
    WHERE d.department_id = ?
");
$dept_stmt->execute([$department_id]);
$department = $dept_stmt->fetch(PDO::FETCH_ASSOC);

if (!$department) {
    $_SESSION['error'] = "Department not found.";
    header("Location: ../logout.php");
    exit;
}

/* ================= HANDLE POST REQUESTS ================= */
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle different actions
    $action = $_POST['action'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        switch($action) {
            case 'add_program':
                $program_name = trim($_POST['program_name']);
                $program_code = trim($_POST['program_code']);
                $duration_years = intval($_POST['duration_years']);
                $description = trim($_POST['description']);
                
                // Check if program code already exists
                $check = $pdo->prepare("SELECT program_id FROM programs WHERE program_code = ?");
                $check->execute([$program_code]);
                if ($check->fetch()) {
                    throw new Exception("Program code already exists");
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO programs (program_name, program_code, department_id, faculty_id, campus_id, duration_years, description, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $program_name, 
                    $program_code, 
                    $department_id,
                    $department['faculty_id'],
                    $department['campus_id'],
                    $duration_years,
                    $description,
                    $current_user['user_id']
                ]);
                
                $message = "Program added successfully";
                break;
                
            case 'edit_program':
                $program_id = intval($_POST['program_id']);
                $program_name = trim($_POST['program_name']);
                $program_code = trim($_POST['program_code']);
                $duration_years = intval($_POST['duration_years']);
                $description = trim($_POST['description']);
                $status = $_POST['status'];
                
                // Check if program code exists for other programs
                $check = $pdo->prepare("SELECT program_id FROM programs WHERE program_code = ? AND program_id != ?");
                $check->execute([$program_code, $program_id]);
                if ($check->fetch()) {
                    throw new Exception("Program code already exists");
                }
                
                $stmt = $pdo->prepare("
                    UPDATE programs 
                    SET program_name = ?, program_code = ?, duration_years = ?, 
                        description = ?, status = ?, updated_at = NOW()
                    WHERE program_id = ? AND department_id = ?
                ");
                $stmt->execute([$program_name, $program_code, $duration_years, $description, $status, $program_id, $department_id]);
                
                $message = "Program updated successfully";
                break;
                
            case 'delete_program':
                $program_id = intval($_POST['program_id']);
                
                // Check if program has classes
                $check = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE program_id = ?");
                $check->execute([$program_id]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception("Cannot delete program with existing classes");
                }
                
                $stmt = $pdo->prepare("DELETE FROM programs WHERE program_id = ? AND department_id = ?");
                $stmt->execute([$program_id, $department_id]);
                
                $message = "Program deleted successfully";
                break;
                
            case 'add_class':
                $class_name = trim($_POST['class_name']);
                $program_id = intval($_POST['program_id']);
                $study_mode = $_POST['study_mode'];
                $capacity = intval($_POST['capacity']) ?: 0;
                
                // Verify program belongs to this department
                $check = $pdo->prepare("SELECT program_id FROM programs WHERE program_id = ? AND department_id = ?");
                $check->execute([$program_id, $department_id]);
                if (!$check->fetch()) {
                    throw new Exception("Invalid program selected");
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO classes (class_name, program_id, department_id, faculty_id, campus_id, study_mode, capacity, status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', NOW(), NOW())
                ");
                $stmt->execute([
                    $class_name,
                    $program_id,
                    $department_id,
                    $department['faculty_id'],
                    $department['campus_id'],
                    $study_mode,
                    $capacity
                ]);
                
                $message = "Class added successfully";
                break;
                
            case 'edit_class':
                $class_id = intval($_POST['class_id']);
                $class_name = trim($_POST['class_name']);
                $program_id = intval($_POST['program_id']);
                $study_mode = $_POST['study_mode'];
                $capacity = intval($_POST['capacity']) ?: 0;
                $status = $_POST['status'];
                
                // Verify class belongs to this department
                $check = $pdo->prepare("SELECT class_id FROM classes WHERE class_id = ? AND department_id = ?");
                $check->execute([$class_id, $department_id]);
                if (!$check->fetch()) {
                    throw new Exception("Invalid class selected");
                }
                
                $stmt = $pdo->prepare("
                    UPDATE classes 
                    SET class_name = ?, program_id = ?, study_mode = ?, 
                        capacity = ?, status = ?, updated_at = NOW()
                    WHERE class_id = ? AND department_id = ?
                ");
                $stmt->execute([$class_name, $program_id, $study_mode, $capacity, $status, $class_id, $department_id]);
                
                $message = "Class updated successfully";
                break;
                
            case 'delete_class':
                $class_id = intval($_POST['class_id']);
                
                // Check if class has students
                $check = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_id = ?");
                $check->execute([$class_id]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception("Cannot delete class with enrolled students");
                }
                
                $stmt = $pdo->prepare("DELETE FROM classes WHERE class_id = ? AND department_id = ?");
                $stmt->execute([$class_id, $department_id]);
                
                $message = "Class deleted successfully";
                break;
                
            case 'add_subject':
                $subject_name = trim($_POST['subject_name']);
                $subject_code = trim($_POST['subject_code']);
                $credit_hours = intval($_POST['credit_hours']) ?: 3;
                $semester = intval($_POST['semester']) ?: 1;
                $description = trim($_POST['description']);
                
                // Check if subject code exists
                $check = $pdo->prepare("SELECT subject_id FROM subject WHERE subject_code = ?");
                $check->execute([$subject_code]);
                if ($check->fetch()) {
                    throw new Exception("Subject code already exists");
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO subject (subject_name, subject_code, department_id, credit_hours, semester, description, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$subject_name, $subject_code, $department_id, $credit_hours, $semester, $description, $current_user['user_id']]);
                
                $message = "Subject added successfully";
                break;
                
            case 'edit_subject':
                $subject_id = intval($_POST['subject_id']);
                $subject_name = trim($_POST['subject_name']);
                $subject_code = trim($_POST['subject_code']);
                $credit_hours = intval($_POST['credit_hours']) ?: 3;
                $semester = intval($_POST['semester']) ?: 1;
                $description = trim($_POST['description']);
                $status = $_POST['status'];
                
                // Check if subject code exists for other subjects
                $check = $pdo->prepare("SELECT subject_id FROM subject WHERE subject_code = ? AND subject_id != ?");
                $check->execute([$subject_code, $subject_id]);
                if ($check->fetch()) {
                    throw new Exception("Subject code already exists");
                }
                
                $stmt = $pdo->prepare("
                    UPDATE subject 
                    SET subject_name = ?, subject_code = ?, credit_hours = ?, 
                        semester = ?, description = ?, status = ?, updated_at = NOW()
                    WHERE subject_id = ? AND department_id = ?
                ");
                $stmt->execute([$subject_name, $subject_code, $credit_hours, $semester, $description, $status, $subject_id, $department_id]);
                
                $message = "Subject updated successfully";
                break;
                
            case 'delete_subject':
                $subject_id = intval($_POST['subject_id']);
                
                // Check if subject is used in timetable
                $check = $pdo->prepare("SELECT COUNT(*) FROM timetable WHERE subject_id = ?");
                $check->execute([$subject_id]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception("Cannot delete subject used in timetable");
                }
                
                $stmt = $pdo->prepare("DELETE FROM subject WHERE subject_id = ? AND department_id = ?");
                $stmt->execute([$subject_id, $department_id]);
                
                $message = "Subject deleted successfully";
                break;
                
            case 'add_teacher':
                $teacher_name = trim($_POST['teacher_name']);
                $email = trim($_POST['email']);
                $phone_number = trim($_POST['phone_number']);
                $qualification = trim($_POST['qualification']);
                $position_title = trim($_POST['position_title']);
                $gender = $_POST['gender'] ?? null;
                
                // Check if email exists
                $check = $pdo->prepare("SELECT teacher_id FROM teachers WHERE email = ?");
                $check->execute([$email]);
                if ($check->fetch()) {
                    throw new Exception("Email already exists");
                }
                
                // Generate teacher_uuid
                $teacher_uuid = 'TCH' . time() . rand(100, 999);
                
                $stmt = $pdo->prepare("
                    INSERT INTO teachers (teacher_uuid, teacher_name, email, phone_number, qualification, position_title, gender, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())
                ");
                $stmt->execute([
                    $teacher_uuid,
                    $teacher_name, 
                    $email, 
                    $phone_number, 
                    $qualification,
                    $position_title,
                    $gender
                ]);
                
                $message = "Teacher added successfully";
                break;
                
            case 'edit_teacher':
                $teacher_id = intval($_POST['teacher_id']);
                $teacher_name = trim($_POST['teacher_name']);
                $email = trim($_POST['email']);
                $phone_number = trim($_POST['phone_number']);
                $qualification = trim($_POST['qualification']);
                $position_title = trim($_POST['position_title']);
                $gender = $_POST['gender'] ?? null;
                $status = $_POST['status'];
                
                // Check if email exists for other teachers
                $check = $pdo->prepare("SELECT teacher_id FROM teachers WHERE email = ? AND teacher_id != ?");
                $check->execute([$email, $teacher_id]);
                if ($check->fetch()) {
                    throw new Exception("Email already exists");
                }
                
                $stmt = $pdo->prepare("
                    UPDATE teachers 
                    SET teacher_name = ?, email = ?, phone_number = ?, 
                        qualification = ?, position_title = ?, gender = ?, status = ?
                    WHERE teacher_id = ?
                ");
                $stmt->execute([$teacher_name, $email, $phone_number, $qualification, $position_title, $gender, $status, $teacher_id]);
                
                $message = "Teacher updated successfully";
                break;
                
            case 'delete_teacher':
                $teacher_id = intval($_POST['teacher_id']);
                
                // Check if teacher has timetable assignments
                $check = $pdo->prepare("SELECT COUNT(*) FROM timetable WHERE teacher_id = ?");
                $check->execute([$teacher_id]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception("Cannot delete teacher with timetable assignments");
                }
                
                $stmt = $pdo->prepare("DELETE FROM teachers WHERE teacher_id = ?");
                $stmt->execute([$teacher_id]);
                
                $message = "Teacher deleted successfully";
                break;
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}

/* ================= FETCH DATA FOR DISPLAY ================= */

// Get programs in this department
$programs = $pdo->prepare("
    SELECT p.*, 
           (SELECT COUNT(*) FROM classes WHERE program_id = p.program_id) AS total_classes,
           (SELECT COUNT(DISTINCT s.student_id) 
            FROM students s 
            JOIN classes c ON c.class_id = s.class_id 
            WHERE c.program_id = p.program_id) AS total_students
    FROM programs p
    WHERE p.department_id = ?
    ORDER BY p.program_name
");
$programs->execute([$department_id]);
$programs_list = $programs->fetchAll(PDO::FETCH_ASSOC);

// Get classes in this department
$classes = $pdo->prepare("
    SELECT c.*, p.program_name,
           (SELECT COUNT(*) FROM students WHERE class_id = c.class_id) AS enrolled_students
    FROM classes c
    LEFT JOIN programs p ON p.program_id = c.program_id
    WHERE c.department_id = ?
    ORDER BY c.class_name
");
$classes->execute([$department_id]);
$classes_list = $classes->fetchAll(PDO::FETCH_ASSOC);

// Get subjects in this department
$subjects = $pdo->prepare("
    SELECT s.*,
           (SELECT COUNT(*) FROM timetable WHERE subject_id = s.subject_id) AS used_in_timetable
    FROM subject s
    WHERE s.department_id = ?
    ORDER BY s.subject_name
");
$subjects->execute([$department_id]);
$subjects_list = $subjects->fetchAll(PDO::FETCH_ASSOC);

// Get teachers
$teachers = $pdo->prepare("
    SELECT t.*,
           (SELECT COUNT(*) FROM timetable WHERE teacher_id = t.teacher_id) AS assigned_classes
    FROM teachers t
    ORDER BY t.teacher_name
");
$teachers->execute();
$teachers_list = $teachers->fetchAll(PDO::FETCH_ASSOC);

// Get students in this department
$students = $pdo->prepare("
    SELECT s.*, c.class_name, p.program_name,
           (SELECT COUNT(*) FROM attendance WHERE student_id = s.student_id) AS attendance_count
    FROM students s
    LEFT JOIN classes c ON c.class_id = s.class_id
    LEFT JOIN programs p ON p.program_id = c.program_id
    WHERE c.department_id = ?
    ORDER BY s.full_name
    LIMIT 50
");
$students->execute([$department_id]);
$students_list = $students->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = [];

// Student statistics
$stmt = $pdo->prepare("SELECT COUNT(*) FROM students s JOIN classes c ON c.class_id = s.class_id WHERE c.department_id = ?");
$stmt->execute([$department_id]);
$stats['total_students'] = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM students s JOIN classes c ON c.class_id = s.class_id WHERE c.department_id = ? AND s.status = 'Active'");
$stmt->execute([$department_id]);
$stats['active_students'] = $stmt->fetchColumn();

// Teacher statistics
$stmt = $pdo->prepare("SELECT COUNT(*) FROM teachers");
$stmt->execute();
$stats['total_teachers'] = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM teachers WHERE status = 'active'");
$stmt->execute();
$stats['active_teachers'] = $stmt->fetchColumn();

// Program statistics
$stats['total_programs'] = count($programs_list);
$stats['total_classes'] = count($classes_list);
$stats['total_subjects'] = count($subjects_list);

// Attendance statistics for today
$today = date('Y-m-d');
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM attendance a
    JOIN students s ON s.student_id = a.student_id
    JOIN classes c ON c.class_id = s.class_id
    WHERE c.department_id = ? AND DATE(a.attendance_date) = ?
");
$stmt->execute([$department_id, $today]);
$stats['today_attendance'] = $stmt->fetchColumn();

// Get upcoming timetable
$upcoming_timetable = $pdo->prepare("
    SELECT t.*, s.subject_name, c.class_name, tchr.teacher_name,
           CONCAT(t.day_of_week, ' ', t.start_time, '-', t.end_time) AS schedule
    FROM timetable t
    JOIN subject s ON s.subject_id = t.subject_id
    JOIN classes c ON c.class_id = t.class_id
    JOIN teachers tchr ON tchr.teacher_id = t.teacher_id
    WHERE c.department_id = ? AND t.status = 'active'
    ORDER BY FIELD(t.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'),
             t.start_time
    LIMIT 10
");
$upcoming_timetable->execute([$department_id]);
$timetable_list = $upcoming_timetable->fetchAll(PDO::FETCH_ASSOC);

include('../includes/header.php');
?>

<style>
/* =====================================================
   HORMUUD UNIVERSITY - OFFICIAL COLORS
   ===================================================== */
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
  --hormuud-purple: #6B2E8A;
  --hormuud-teal: #009688;
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: 'Poppins', 'Segoe UI', sans-serif;
}

body {
  background: #f0f2f5;
  color: var(--hormuud-dark);
  min-height: 100vh;
}

.dashboard-container {
  margin-left: 250px;
  padding: 30px;
  margin-top: 70px;
  transition: all 0.3s ease;
}

body.sidebar-collapsed .dashboard-container {
  margin-left: 70px;
}

/* =====================================================
   PAGE HEADER
   ===================================================== */
.page-header {
  background: linear-gradient(135deg, var(--hormuud-blue), #004b8f);
  color: var(--hormuud-white);
  padding: 30px 35px;
  border-radius: 20px;
  margin-bottom: 30px;
  box-shadow: 0 10px 30px rgba(0, 114, 206, 0.3);
  position: relative;
  overflow: hidden;
}

.page-header::before {
  content: '';
  position: absolute;
  top: -50%;
  right: -50%;
  width: 200%;
  height: 200%;
  background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
  background-size: 30px 30px;
  opacity: 0.1;
  animation: float 20s linear infinite;
}

@keyframes float {
  0% { transform: translate(0, 0) rotate(0deg); }
  100% { transform: translate(-50px, -50px) rotate(360deg); }
}

.page-header h1 {
  margin: 0 0 15px 0;
  font-size: 32px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 15px;
}

.page-header h1 i {
  background: rgba(255, 255, 255, 0.2);
  padding: 15px;
  border-radius: 15px;
  backdrop-filter: blur(10px);
  box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}

.dept-info {
  display: flex;
  gap: 20px;
  flex-wrap: wrap;
  margin-top: 20px;
}

.dept-info span {
  background: rgba(255, 255, 255, 0.15);
  padding: 10px 20px;
  border-radius: 30px;
  font-size: 14px;
  font-weight: 500;
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.2);
  display: flex;
  align-items: center;
  gap: 8px;
}

.dept-info span i {
  color: var(--hormuud-gold);
}

/* =====================================================
   STATS GRID
   ===================================================== */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 25px;
  margin-bottom: 35px;
}

.stat-card {
  background: var(--hormuud-white);
  border-radius: 20px;
  padding: 25px;
  box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
  display: flex;
  align-items: center;
  transition: all 0.3s ease;
  border-left: 6px solid;
  position: relative;
  overflow: hidden;
}

.stat-card::after {
  content: '';
  position: absolute;
  top: -20px;
  right: -20px;
  width: 100px;
  height: 100px;
  background: rgba(0, 0, 0, 0.02);
  border-radius: 50%;
}

.stat-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
}

.stat-card.students { border-left-color: var(--hormuud-blue); }
.stat-card.teachers { border-left-color: var(--hormuud-green); }
.stat-card.programs { border-left-color: var(--hormuud-gold); }
.stat-card.subjects { border-left-color: var(--hormuud-purple); }

.stat-icon {
  width: 70px;
  height: 70px;
  border-radius: 18px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 20px;
  font-size: 32px;
  color: var(--hormuud-white);
  box-shadow: 0 10px 20px rgba(0,0,0,0.1);
}

.stat-card.students .stat-icon { background: linear-gradient(135deg, var(--hormuud-blue), #004b8f); }
.stat-card.teachers .stat-icon { background: linear-gradient(135deg, var(--hormuud-green), #005a2b); }
.stat-card.programs .stat-icon { background: linear-gradient(135deg, var(--hormuud-gold), #d99b00); }
.stat-card.subjects .stat-icon { background: linear-gradient(135deg, var(--hormuud-purple), #4a1e5f); }

.stat-content {
  flex: 1;
}

.stat-content h3 {
  margin: 0 0 8px 0;
  font-size: 15px;
  color: #666;
  font-weight: 500;
  letter-spacing: 0.5px;
}

.stat-number {
  font-size: 32px;
  font-weight: 700;
  color: var(--hormuud-dark);
  margin: 0;
  line-height: 1.2;
}

.stat-desc {
  font-size: 13px;
  color: #999;
  margin-top: 5px;
  display: flex;
  align-items: center;
  gap: 5px;
}

.stat-desc i {
  font-size: 12px;
  color: var(--hormuud-green);
}

/* =====================================================
   QUICK ACTIONS
   ===================================================== */
.quick-actions {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 20px;
  margin-bottom: 35px;
}

.quick-action-card {
  background: var(--hormuud-white);
  padding: 25px 20px;
  border-radius: 15px;
  text-align: center;
  cursor: pointer;
  transition: all 0.3s ease;
  border: 1px solid #eee;
  box-shadow: 0 5px 15px rgba(0,0,0,0.03);
  position: relative;
  overflow: hidden;
}

.quick-action-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(90deg, var(--hormuud-blue), var(--hormuud-green));
  transform: scaleX(0);
  transition: transform 0.3s ease;
}

.quick-action-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 15px 30px rgba(0, 114, 206, 0.1);
  border-color: transparent;
}

.quick-action-card:hover::before {
  transform: scaleX(1);
}

.quick-action-card i {
  font-size: 40px;
  margin-bottom: 15px;
  background: linear-gradient(135deg, var(--hormuud-blue), var(--hormuud-green));
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}

.quick-action-card h3 {
  margin: 0 0 5px 0;
  font-size: 16px;
  font-weight: 600;
  color: var(--hormuud-dark);
}

.quick-action-card p {
  margin: 0;
  font-size: 12px;
  color: #999;
}

/* =====================================================
   SECTIONS
   ===================================================== */
.section {
  background: var(--hormuud-white);
  border-radius: 20px;
  padding: 25px;
  margin-bottom: 30px;
  box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
  border: 1px solid #f0f0f0;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 25px;
  padding-bottom: 15px;
  border-bottom: 2px solid #f0f0f0;
}

.section-header h2 {
  margin: 0;
  font-size: 20px;
  font-weight: 600;
  color: var(--hormuud-dark);
  display: flex;
  align-items: center;
  gap: 10px;
}

.section-header h2 i {
  color: var(--hormuud-white);
  background: linear-gradient(135deg, var(--hormuud-blue), var(--hormuud-green));
  padding: 10px;
  border-radius: 12px;
  font-size: 18px;
  box-shadow: 0 5px 10px rgba(0,114,206,0.2);
}

.section-header .badge {
  background: linear-gradient(135deg, var(--hormuud-blue), var(--hormuud-green));
  color: var(--hormuud-white);
  padding: 6px 15px;
  border-radius: 30px;
  font-size: 12px;
  font-weight: 600;
  box-shadow: 0 3px 10px rgba(0,114,206,0.2);
}

/* =====================================================
   BUTTONS
   ===================================================== */
.btn {
  padding: 12px 25px;
  border: none;
  border-radius: 10px;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  transition: all 0.3s ease;
  position: relative;
  overflow: hidden;
}

.btn::before {
  content: '';
  position: absolute;
  top: 50%;
  left: 50%;
  width: 0;
  height: 0;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.2);
  transform: translate(-50%, -50%);
  transition: width 0.6s, height 0.6s;
}

.btn:hover::before {
  width: 300px;
  height: 300px;
}

.btn-primary {
  background: linear-gradient(135deg, var(--hormuud-blue), #004b8f);
  color: var(--hormuud-white);
  box-shadow: 0 5px 15px rgba(0, 114, 206, 0.3);
}

.btn-success {
  background: linear-gradient(135deg, var(--hormuud-green), #005a2b);
  color: var(--hormuud-white);
  box-shadow: 0 5px 15px rgba(0, 168, 89, 0.3);
}

.btn-danger {
  background: linear-gradient(135deg, var(--hormuud-red), #8b1e1e);
  color: var(--hormuud-white);
  box-shadow: 0 5px 15px rgba(198, 40, 40, 0.3);
}

.btn-warning {
  background: linear-gradient(135deg, var(--hormuud-orange), #c45d00);
  color: var(--hormuud-white);
  box-shadow: 0 5px 15px rgba(245, 124, 0, 0.3);
}

.btn-outline {
  background: transparent;
  border: 2px solid var(--hormuud-blue);
  color: var(--hormuud-blue);
}

.btn-outline:hover {
  background: var(--hormuud-blue);
  color: var(--hormuud-white);
  transform: translateY(-2px);
  box-shadow: 0 5px 15px rgba(0, 114, 206, 0.3);
}

.btn-sm {
  padding: 8px 15px;
  font-size: 12px;
}

/* =====================================================
   TABLES
   ===================================================== */
.table-wrapper {
  overflow-x: auto;
  border-radius: 15px;
  border: 1px solid #f0f0f0;
  background: var(--hormuud-white);
}

table {
  width: 100%;
  border-collapse: collapse;
  min-width: 700px;
}

thead {
  background: linear-gradient(135deg, #f8f9fa, #f0f2f5);
}

th {
  padding: 18px 15px;
  text-align: left;
  font-weight: 600;
  font-size: 13px;
  color: var(--hormuud-dark);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  border-bottom: 2px solid #e0e0e0;
}

td {
  padding: 15px;
  border-bottom: 1px solid #f0f0f0;
  font-size: 14px;
  color: #555;
}

tr:hover {
  background: #f5f9ff;
}

tr:last-child td {
  border-bottom: none;
}

/* =====================================================
   STATUS BADGES
   ===================================================== */
.status-badge {
  display: inline-block;
  padding: 6px 15px;
  border-radius: 30px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.status-active {
  background: linear-gradient(135deg, rgba(0, 168, 89, 0.1), rgba(0, 168, 89, 0.2));
  color: var(--hormuud-green);
  border: 1px solid rgba(0, 168, 89, 0.2);
}

.status-inactive {
  background: linear-gradient(135deg, rgba(198, 40, 40, 0.1), rgba(198, 40, 40, 0.2));
  color: var(--hormuud-red);
  border: 1px solid rgba(198, 40, 40, 0.2);
}

.status-Active {
  background: linear-gradient(135deg, rgba(0, 168, 89, 0.1), rgba(0, 168, 89, 0.2));
  color: var(--hormuud-green);
  border: 1px solid rgba(0, 168, 89, 0.2);
}

.status-Inactive {
  background: linear-gradient(135deg, rgba(198, 40, 40, 0.1), rgba(198, 40, 40, 0.2));
  color: var(--hormuud-red);
  border: 1px solid rgba(198, 40, 40, 0.2);
}

/* =====================================================
   ALERTS
   ===================================================== */
.alert {
  padding: 18px 25px;
  border-radius: 15px;
  margin-bottom: 25px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  animation: slideDown 0.5s ease;
}

@keyframes slideDown {
  from {
    opacity: 0;
    transform: translateY(-20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.alert-success {
  background: linear-gradient(135deg, rgba(0, 168, 89, 0.1), rgba(0, 168, 89, 0.2));
  color: var(--hormuud-green);
  border-left: 6px solid var(--hormuud-green);
  border-radius: 10px;
}

.alert-error {
  background: linear-gradient(135deg, rgba(198, 40, 40, 0.1), rgba(198, 40, 40, 0.2));
  color: var(--hormuud-red);
  border-left: 6px solid var(--hormuud-red);
  border-radius: 10px;
}

.alert i {
  font-size: 20px;
  margin-right: 10px;
}

.alert .close-alert {
  cursor: pointer;
  font-size: 20px;
  opacity: 0.7;
  transition: opacity 0.3s;
}

.alert .close-alert:hover {
  opacity: 1;
}

/* =====================================================
   MODALS
   ===================================================== */
.modal {
  display: none;
  position: fixed;
  z-index: 1000;
  left: 0;
  top: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.6);
  overflow-y: auto;
  backdrop-filter: blur(8px);
  animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.modal-content {
  background: var(--hormuud-white);
  margin: 50px auto;
  padding: 35px;
  border-radius: 25px;
  width: 90%;
  max-width: 600px;
  position: relative;
  animation: modalSlideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
  border-top: 8px solid var(--hormuud-green);
  box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3);
}

@keyframes modalSlideIn {
  from {
    opacity: 0;
    transform: translateY(-70px) scale(0.95);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

.modal-content h2 {
  margin: 0 0 25px 0;
  color: var(--hormuud-dark);
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
  background: linear-gradient(135deg, var(--hormuud-green), var(--hormuud-dark-green));
  padding: 12px;
  border-radius: 15px;
  font-size: 20px;
  box-shadow: 0 5px 15px rgba(0, 168, 89, 0.3);
}

.close {
  position: absolute;
  right: 25px;
  top: 25px;
  font-size: 30px;
  font-weight: normal;
  color: #999;
  cursor: pointer;
  width: 45px;
  height: 45px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  transition: all 0.3s ease;
  background: rgba(0, 0, 0, 0.05);
}

.close:hover {
  background: rgba(198, 40, 40, 0.1);
  color: var(--hormuud-red);
  transform: rotate(90deg);
}

/* =====================================================
   FORMS
   ===================================================== */
.form-group {
  margin-bottom: 25px;
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

.form-group label::before {
  content: '';
  position: absolute;
  left: 0;
  top: 2px;
  height: 16px;
  width: 3px;
  background: linear-gradient(135deg, var(--hormuud-green), var(--hormuud-blue));
  border-radius: 3px;
}

.form-group input,
.form-group select,
.form-group textarea {
  width: 100%;
  padding: 14px 18px;
  border: 2px solid #e0e0e0;
  border-radius: 12px;
  font-size: 14px;
  transition: all 0.3s ease;
  background: #fafafa;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
  outline: none;
  border-color: var(--hormuud-green);
  background: var(--hormuud-white);
  box-shadow: 0 0 0 4px rgba(0, 168, 89, 0.1);
  transform: translateY(-2px);
}

.form-group input:hover,
.form-group select:hover,
.form-group textarea:hover {
  border-color: var(--hormuud-blue);
}

/* =====================================================
   TIMETABLE CARD
   ===================================================== */
.timetable-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 20px;
  margin-top: 20px;
}

.timetable-card {
  background: linear-gradient(135deg, #f8f9fa, #f0f2f5);
  border-radius: 15px;
  padding: 20px;
  border-left: 6px solid var(--hormuud-blue);
  transition: all 0.3s ease;
}

.timetable-card:hover {
  transform: translateX(5px);
  box-shadow: 0 10px 25px rgba(0, 114, 206, 0.1);
}

.timetable-card .day {
  font-size: 16px;
  font-weight: 700;
  color: var(--hormuud-blue);
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.timetable-card .time {
  font-size: 14px;
  color: #666;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.timetable-card .subject {
  font-size: 16px;
  font-weight: 600;
  color: var(--hormuud-dark);
  margin-bottom: 5px;
}

.timetable-card .details {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
  color: #888;
}

/* =====================================================
   RESPONSIVE
   ===================================================== */
@media (max-width: 1024px) {
  .dashboard-container {
    margin-left: 250px;
  }
}

@media (max-width: 768px) {
  .dashboard-container {
    margin-left: 0 !important;
    padding: 20px;
  }
  
  .page-header {
    padding: 25px 20px;
  }
  
  .page-header h1 {
    font-size: 24px;
  }
  
  .stats-grid {
    grid-template-columns: 1fr;
  }
  
  .quick-actions {
    grid-template-columns: 1fr 1fr;
  }
  
  .modal-content {
    margin: 20px;
    padding: 25px 20px;
  }
  
  .dept-info {
    flex-direction: column;
    gap: 10px;
  }
  
  .dept-info span {
    width: 100%;
  }
}

@media (max-width: 480px) {
  .quick-actions {
    grid-template-columns: 1fr;
  }
  
  .section-header {
    flex-direction: column;
    gap: 15px;
    align-items: flex-start;
  }
  
  .btn {
    width: 100%;
    justify-content: center;
  }
}
</style>

<div class="dashboard-container">
    <!-- Display Messages -->
    <?php if ($message): ?>
        <div class="alert alert-success">
            <span><i class="fa fa-check-circle"></i> <?= htmlspecialchars($message) ?></span>
            <span class="close-alert" onclick="this.parentElement.remove()">&times;</span>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">
            <span><i class="fa fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></span>
            <span class="close-alert" onclick="this.parentElement.remove()">&times;</span>
        </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header">
        <h1>
            <i class="fa fa-building"></i> 
            <?= htmlspecialchars($department['department_name']) ?>
        </h1>
        <div class="dept-info">
            <span><i class="fa fa-university"></i> <?= htmlspecialchars($department['faculty_name']) ?></span>
            <span><i class="fa fa-map-marker"></i> <?= htmlspecialchars($department['campus_name']) ?></span>
            <span><i class="fa fa-calendar"></i> <?= date('l, F j, Y') ?></span>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card students">
            <div class="stat-icon">
                <i class="fa fa-users"></i>
            </div>
            <div class="stat-content">
                <h3>Total Students</h3>
                <p class="stat-number"><?= number_format($stats['total_students']) ?></p>
                <p class="stat-desc">
                    <i class="fa fa-circle"></i> <?= number_format($stats['active_students']) ?> active
                </p>
            </div>
        </div>

        <div class="stat-card teachers">
            <div class="stat-icon">
                <i class="fa fa-chalkboard-teacher"></i>
            </div>
            <div class="stat-content">
                <h3>Teachers</h3>
                <p class="stat-number"><?= number_format($stats['total_teachers']) ?></p>
                <p class="stat-desc">
                    <i class="fa fa-circle"></i> <?= number_format($stats['active_teachers']) ?> active
                </p>
            </div>
        </div>

        <div class="stat-card programs">
            <div class="stat-icon">
                <i class="fa fa-book"></i>
            </div>
            <div class="stat-content">
                <h3>Programs & Classes</h3>
                <p class="stat-number"><?= number_format($stats['total_programs']) ?></p>
                <p class="stat-desc">
                    <i class="fa fa-door-open"></i> <?= number_format($stats['total_classes']) ?> classes
                </p>
            </div>
        </div>

        <div class="stat-card subjects">
            <div class="stat-icon">
                <i class="fa fa-graduation-cap"></i>
            </div>
            <div class="stat-content">
                <h3>Subjects</h3>
                <p class="stat-number"><?= number_format($stats['total_subjects']) ?></p>
                <p class="stat-desc">
                    <i class="fa fa-clock"></i> <?= number_format($stats['today_attendance']) ?> attended today
                </p>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <div class="quick-action-card" onclick="openModal('addProgramModal')">
            <i class="fa fa-layer-group"></i>
            <h3>Add Program</h3>
            <p>Create new academic program</p>
        </div>
        <div class="quick-action-card" onclick="openModal('addClassModal')">
            <i class="fa fa-door-open"></i>
            <h3>Add Class</h3>
            <p>Create new class section</p>
        </div>
        <div class="quick-action-card" onclick="openModal('addSubjectModal')">
            <i class="fa fa-book-open"></i>
            <h3>Add Subject</h3>
            <p>Add new course subject</p>
        </div>
        <div class="quick-action-card" onclick="openModal('addTeacherModal')">
            <i class="fa fa-user-tie"></i>
            <h3>Add Teacher</h3>
            <p>Register new teacher</p>
        </div>
    </div>

    <!-- Upcoming Timetable -->
    <?php if (!empty($timetable_list)): ?>
    <div class="section">
        <div class="section-header">
            <h2><i class="fa fa-calendar-alt"></i> Upcoming Classes</h2>
            <span class="badge">This Week</span>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Day</th>
                        <th>Time</th>
                        <th>Subject</th>
                        <th>Class</th>
                        <th>Teacher</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($timetable_list as $tt): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($tt['day_of_week']) ?></strong></td>
                        <td><?= htmlspecialchars($tt['start_time']) ?> - <?= htmlspecialchars($tt['end_time']) ?></td>
                        <td><?= htmlspecialchars($tt['subject_name']) ?></td>
                        <td><?= htmlspecialchars($tt['class_name']) ?></td>
                        <td><?= htmlspecialchars($tt['teacher_name']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Programs Section -->
    <div class="section">
        <div class="section-header">
            <h2><i class="fa fa-layer-group"></i> Programs</h2>
            <button class="btn btn-primary" onclick="openModal('addProgramModal')">
                <i class="fa fa-plus"></i> Add Program
            </button>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Program Code</th>
                        <th>Program Name</th>
                        <th>Duration</th>
                        <th>Classes</th>
                        <th>Students</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($programs_list as $program): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($program['program_code']) ?></strong></td>
                        <td><?= htmlspecialchars($program['program_name']) ?></td>
                        <td><?= $program['duration_years'] ?> years</td>
                        <td><?= $program['total_classes'] ?></td>
                        <td><?= $program['total_students'] ?></td>
                        <td>
                            <span class="status-badge status-<?= strtolower($program['status']) ?>">
                                <?= htmlspecialchars($program['status']) ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-outline btn-sm" onclick="editProgram(<?= htmlspecialchars(json_encode($program)) ?>)">
                                <i class="fa fa-edit"></i>
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="deleteProgram(<?= $program['program_id'] ?>, '<?= htmlspecialchars($program['program_name']) ?>')">
                                <i class="fa fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($programs_list)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #999;">
                            <i class="fa fa-folder-open" style="font-size: 40px; display: block; margin-bottom: 15px;"></i>
                            No programs found
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Classes Section -->
    <div class="section">
        <div class="section-header">
            <h2><i class="fa fa-door-open"></i> Classes</h2>
            <button class="btn btn-primary" onclick="openModal('addClassModal')">
                <i class="fa fa-plus"></i> Add Class
            </button>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Class Name</th>
                        <th>Program</th>
                        <th>Study Mode</th>
                        <th>Capacity</th>
                        <th>Enrolled</th>
                        <th>Available</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes_list as $class): 
                        $available = ($class['capacity'] ?? 0) - ($class['enrolled_students'] ?? 0);
                        $capacity_percentage = ($class['capacity'] ?? 0) > 0 ? round(($class['enrolled_students'] ?? 0) / $class['capacity'] * 100) : 0;
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($class['class_name']) ?></strong></td>
                        <td><?= htmlspecialchars($class['program_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($class['study_mode'] ?? '-') ?></td>
                        <td><?= isset($class['capacity']) ? htmlspecialchars($class['capacity']) : '0' ?></td>
                        <td><?= $class['enrolled_students'] ?? 0 ?></td>
                        <td>
                            <span style="color: <?= $available > 10 ? 'var(--hormuud-green)' : ($available > 0 ? 'var(--hormuud-orange)' : 'var(--hormuud-red)'); ?>; font-weight: 600;">
                                <?= $available >= 0 ? $available : 0 ?>
                            </span>
                            <span style="font-size: 11px; color: #999; margin-left: 5px;">
                                (<?= $capacity_percentage ?>%)
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?= strtolower($class['status'] ?? 'Active') ?>">
                                <?= htmlspecialchars($class['status'] ?? 'Active') ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-outline btn-sm" onclick="editClass(<?= htmlspecialchars(json_encode($class)) ?>)">
                                <i class="fa fa-edit"></i>
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="deleteClass(<?= $class['class_id'] ?>, '<?= htmlspecialchars($class['class_name']) ?>')">
                                <i class="fa fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($classes_list)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #999;">
                            <i class="fa fa-door-closed" style="font-size: 40px; display: block; margin-bottom: 15px;"></i>
                            No classes found
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Subjects Section -->
    <div class="section">
        <div class="section-header">
            <h2><i class="fa fa-book-open"></i> Subjects</h2>
            <button class="btn btn-primary" onclick="openModal('addSubjectModal')">
                <i class="fa fa-plus"></i> Add Subject
            </button>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Subject Code</th>
                        <th>Subject Name</th>
                        <th>Credit Hours</th>
                        <th>Semester</th>
                        <th>Used in Timetable</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subjects_list as $subject): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($subject['subject_code']) ?></strong></td>
                        <td><?= htmlspecialchars($subject['subject_name']) ?></td>
                        <td><?= isset($subject['credit_hours']) ? htmlspecialchars($subject['credit_hours']) : '3' ?></td>
                        <td><?= isset($subject['semester']) ? htmlspecialchars($subject['semester']) : '1' ?></td>
                        <td>
                            <span class="status-badge" style="background: <?= ($subject['used_in_timetable'] ?? 0) > 0 ? 'rgba(0,168,89,0.1)' : '#f0f0f0'; ?>; color: <?= ($subject['used_in_timetable'] ?? 0) > 0 ? 'var(--hormuud-green)' : '#999'; ?>;">
                                <?= $subject['used_in_timetable'] ?? 0 ?> classes
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?= strtolower($subject['status'] ?? 'active') ?>">
                                <?= htmlspecialchars($subject['status'] ?? 'active') ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-outline btn-sm" onclick="editSubject(<?= htmlspecialchars(json_encode($subject)) ?>)">
                                <i class="fa fa-edit"></i>
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="deleteSubject(<?= $subject['subject_id'] ?>, '<?= htmlspecialchars($subject['subject_name']) ?>')">
                                <i class="fa fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($subjects_list)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #999;">
                            <i class="fa fa-book" style="font-size: 40px; display: block; margin-bottom: 15px;"></i>
                            No subjects found
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Teachers Section -->
    <div class="section">
        <div class="section-header">
            <h2><i class="fa fa-chalkboard-teacher"></i> Teachers</h2>
            <button class="btn btn-primary" onclick="openModal('addTeacherModal')">
                <i class="fa fa-plus"></i> Add Teacher
            </button>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Teacher Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Qualification</th>
                        <th>Position</th>
                        <th>Classes Assigned</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teachers_list as $teacher): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($teacher['teacher_name']) ?></strong></td>
                        <td><?= htmlspecialchars($teacher['email']) ?></td>
                        <td><?= htmlspecialchars($teacher['phone_number'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($teacher['qualification'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($teacher['position_title'] ?? '-') ?></td>
                        <td><?= $teacher['assigned_classes'] ?? 0 ?></td>
                        <td>
                            <span class="status-badge status-<?= strtolower($teacher['status']) ?>">
                                <?= htmlspecialchars($teacher['status']) ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-outline btn-sm" onclick="editTeacher(<?= htmlspecialchars(json_encode($teacher)) ?>)">
                                <i class="fa fa-edit"></i>
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="deleteTeacher(<?= $teacher['teacher_id'] ?>, '<?= htmlspecialchars($teacher['teacher_name']) ?>')">
                                <i class="fa fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($teachers_list)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #999;">
                            <i class="fa fa-user-slash" style="font-size: 40px; display: block; margin-bottom: 15px;"></i>
                            No teachers found
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Students Section -->
    <div class="section">
        <div class="section-header">
            <h2><i class="fa fa-user-graduate"></i> Recent Students</h2>
            <a href="students.php" class="btn btn-outline">View All Students</a>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Reg No</th>
                        <th>Student Name</th>
                        <th>Class</th>
                        <th>Program</th>
                        <th>Attendance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students_list as $student): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($student['reg_no']) ?></strong></td>
                        <td><?= htmlspecialchars($student['full_name']) ?></td>
                        <td><?= htmlspecialchars($student['class_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($student['program_name'] ?? '-') ?></td>
                        <td>
                            <span style="color: <?= ($student['attendance_count'] ?? 0) > 10 ? 'var(--hormuud-green)' : 'var(--hormuud-orange)'; ?>;">
                                <?= $student['attendance_count'] ?? 0 ?> records
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?= strtolower($student['status']) ?>">
                                <?= htmlspecialchars($student['status']) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($students_list)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: #999;">
                            <i class="fa fa-user-graduate" style="font-size: 40px; display: block; margin-bottom: 15px;"></i>
                            No students found
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ========== MODALS ========== -->

    <!-- Add Program Modal -->
    <div id="addProgramModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addProgramModal')">&times;</span>
            <h2><i class="fa fa-layer-group"></i> Add New Program</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_program">
                
                <div class="form-group">
                    <label>Program Name</label>
                    <input type="text" name="program_name" placeholder="e.g., Bachelor of Computer Science" required>
                </div>
                
                <div class="form-group">
                    <label>Program Code</label>
                    <input type="text" name="program_code" placeholder="e.g., BCS-101" required>
                </div>
                
                <div class="form-group">
                    <label>Duration (Years)</label>
                    <input type="number" name="duration_years" min="1" max="6" value="3" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3" placeholder="Program description..."></textarea>
                </div>
                
                <button type="submit" class="btn btn-success" style="width: 100%;">Add Program</button>
            </form>
        </div>
    </div>

    <!-- Add Class Modal -->
    <div id="addClassModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addClassModal')">&times;</span>
            <h2><i class="fa fa-door-open"></i> Add New Class</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_class">
                
                <div class="form-group">
                    <label>Class Name</label>
                    <input type="text" name="class_name" placeholder="e.g., Year 1, Group A" required>
                </div>
                
                <div class="form-group">
                    <label>Program</label>
                    <select name="program_id" required>
                        <option value="">Select Program</option>
                        <?php foreach ($programs_list as $program): ?>
                            <option value="<?= $program['program_id'] ?>"><?= htmlspecialchars($program['program_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Study Mode</label>
                    <select name="study_mode" required>
                        <option value="Full-Time">Full-Time</option>
                        <option value="Part-Time">Part-Time</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Capacity</label>
                    <input type="number" name="capacity" min="0" value="30" placeholder="Maximum students">
                </div>
                
                <button type="submit" class="btn btn-success" style="width: 100%;">Add Class</button>
            </form>
        </div>
    </div>

    <!-- Add Subject Modal -->
    <div id="addSubjectModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addSubjectModal')">&times;</span>
            <h2><i class="fa fa-book-open"></i> Add New Subject</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_subject">
                
                <div class="form-group">
                    <label>Subject Name</label>
                    <input type="text" name="subject_name" placeholder="e.g., Database Systems" required>
                </div>
                
                <div class="form-group">
                    <label>Subject Code</label>
                    <input type="text" name="subject_code" placeholder="e.g., CS-201" required>
                </div>
                
                <div class="form-group">
                    <label>Credit Hours</label>
                    <input type="number" name="credit_hours" min="1" max="6" value="3">
                </div>
                
                <div class="form-group">
                    <label>Semester</label>
                    <input type="number" name="semester" min="1" max="12" value="1" placeholder="Semester number">
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3" placeholder="Subject description..."></textarea>
                </div>
                
                <button type="submit" class="btn btn-success" style="width: 100%;">Add Subject</button>
            </form>
        </div>
    </div>

    <!-- Add Teacher Modal -->
    <div id="addTeacherModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('addTeacherModal')">&times;</span>
            <h2><i class="fa fa-chalkboard-teacher"></i> Add New Teacher</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_teacher">
                
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="teacher_name" placeholder="e.g., Dr. Ahmed Mohamed" required>
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" placeholder="teacher@hormuud.edu.so" required>
                </div>
                
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone_number" placeholder="e.g., +252 61 234 5678">
                </div>
                
                <div class="form-group">
                    <label>Qualification</label>
                    <input type="text" name="qualification" placeholder="e.g., PhD in Computer Science">
                </div>
                
                <div class="form-group">
                    <label>Position Title</label>
                    <input type="text" name="position_title" placeholder="e.g., Senior Lecturer">
                </div>
                
                <div class="form-group">
                    <label>Gender</label>
                    <select name="gender">
                        <option value="">Select Gender</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-success" style="width: 100%;">Add Teacher</button>
            </form>
        </div>
    </div>

    <!-- Edit Program Modal -->
    <div id="editProgramModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editProgramModal')">&times;</span>
            <h2><i class="fa fa-edit"></i> Edit Program</h2>
            <form method="POST" id="editProgramForm">
                <input type="hidden" name="action" value="edit_program">
                <input type="hidden" name="program_id" id="edit_program_id">
                
                <div class="form-group">
                    <label>Program Name</label>
                    <input type="text" name="program_name" id="edit_program_name" required>
                </div>
                
                <div class="form-group">
                    <label>Program Code</label>
                    <input type="text" name="program_code" id="edit_program_code" required>
                </div>
                
                <div class="form-group">
                    <label>Duration (Years)</label>
                    <input type="number" name="duration_years" id="edit_duration_years" min="1" max="6" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="edit_program_description" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_program_status" required>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">Update Program</button>
            </form>
        </div>
    </div>

    <!-- Edit Class Modal -->
    <div id="editClassModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editClassModal')">&times;</span>
            <h2><i class="fa fa-edit"></i> Edit Class</h2>
            <form method="POST" id="editClassForm">
                <input type="hidden" name="action" value="edit_class">
                <input type="hidden" name="class_id" id="edit_class_id">
                
                <div class="form-group">
                    <label>Class Name</label>
                    <input type="text" name="class_name" id="edit_class_name" required>
                </div>
                
                <div class="form-group">
                    <label>Program</label>
                    <select name="program_id" id="edit_class_program_id" required>
                        <option value="">Select Program</option>
                        <?php foreach ($programs_list as $program): ?>
                            <option value="<?= $program['program_id'] ?>"><?= htmlspecialchars($program['program_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Study Mode</label>
                    <select name="study_mode" id="edit_class_study_mode" required>
                        <option value="Full-Time">Full-Time</option>
                        <option value="Part-Time">Part-Time</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Capacity</label>
                    <input type="number" name="capacity" id="edit_class_capacity" min="0">
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_class_status" required>
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">Update Class</button>
            </form>
        </div>
    </div>

    <!-- Edit Subject Modal -->
    <div id="editSubjectModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editSubjectModal')">&times;</span>
            <h2><i class="fa fa-edit"></i> Edit Subject</h2>
            <form method="POST" id="editSubjectForm">
                <input type="hidden" name="action" value="edit_subject">
                <input type="hidden" name="subject_id" id="edit_subject_id">
                
                <div class="form-group">
                    <label>Subject Name</label>
                    <input type="text" name="subject_name" id="edit_subject_name" required>
                </div>
                
                <div class="form-group">
                    <label>Subject Code</label>
                    <input type="text" name="subject_code" id="edit_subject_code" required>
                </div>
                
                <div class="form-group">
                    <label>Credit Hours</label>
                    <input type="number" name="credit_hours" id="edit_subject_credit_hours" min="1" max="6">
                </div>
                
                <div class="form-group">
                    <label>Semester</label>
                    <input type="number" name="semester" id="edit_subject_semester" min="1" max="12">
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="edit_subject_description" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_subject_status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">Update Subject</button>
            </form>
        </div>
    </div>

    <!-- Edit Teacher Modal -->
    <div id="editTeacherModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editTeacherModal')">&times;</span>
            <h2><i class="fa fa-edit"></i> Edit Teacher</h2>
            <form method="POST" id="editTeacherForm">
                <input type="hidden" name="action" value="edit_teacher">
                <input type="hidden" name="teacher_id" id="edit_teacher_id">
                
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="teacher_name" id="edit_teacher_name" required>
                </div>
                
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="edit_teacher_email" required>
                </div>
                
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone_number" id="edit_teacher_phone">
                </div>
                
                <div class="form-group">
                    <label>Qualification</label>
                    <input type="text" name="qualification" id="edit_teacher_qualification">
                </div>
                
                <div class="form-group">
                    <label>Position Title</label>
                    <input type="text" name="position_title" id="edit_teacher_position_title">
                </div>
                
                <div class="form-group">
                    <label>Gender</label>
                    <select name="gender" id="edit_teacher_gender">
                        <option value="">Select Gender</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="edit_teacher_status" required>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;">Update Teacher</button>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content" style="max-width: 450px;">
            <span class="close" onclick="closeModal('deleteModal')">&times;</span>
            <h2><i class="fa fa-exclamation-triangle" style="color: var(--hormuud-red);"></i> Confirm Delete</h2>
            <p id="deleteMessage" style="text-align: center; margin: 30px 0; font-size: 16px;">Are you sure you want to delete this item?</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" id="deleteAction">
                <input type="hidden" name="program_id" id="deleteId" class="delete-id">
                <input type="hidden" name="class_id" id="deleteClassId" class="delete-id">
                <input type="hidden" name="subject_id" id="deleteSubjectId" class="delete-id">
                <input type="hidden" name="teacher_id" id="deleteTeacherId" class="delete-id">
                <div style="display: flex; gap: 15px; justify-content: center;">
                    <button type="button" class="btn btn-outline" onclick="closeModal('deleteModal')" style="width: 120px;">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="width: 120px;">Delete</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Modal functions
function openModal(modalId) {
    document.getElementById(modalId).style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
        document.body.style.overflow = 'auto';
    }
}

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(modal => {
            if (modal.style.display === 'block') {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        });
    }
});

// Edit Program
function editProgram(program) {
    document.getElementById('edit_program_id').value = program.program_id;
    document.getElementById('edit_program_name').value = program.program_name;
    document.getElementById('edit_program_code').value = program.program_code;
    document.getElementById('edit_duration_years').value = program.duration_years;
    document.getElementById('edit_program_description').value = program.description || '';
    document.getElementById('edit_program_status').value = program.status;
    openModal('editProgramModal');
}

// Edit Class
function editClass(classData) {
    document.getElementById('edit_class_id').value = classData.class_id;
    document.getElementById('edit_class_name').value = classData.class_name;
    document.getElementById('edit_class_program_id').value = classData.program_id;
    document.getElementById('edit_class_study_mode').value = classData.study_mode;
    document.getElementById('edit_class_capacity').value = classData.capacity || 0;
    document.getElementById('edit_class_status').value = classData.status;
    openModal('editClassModal');
}

// Edit Subject
function editSubject(subject) {
    document.getElementById('edit_subject_id').value = subject.subject_id;
    document.getElementById('edit_subject_name').value = subject.subject_name;
    document.getElementById('edit_subject_code').value = subject.subject_code;
    document.getElementById('edit_subject_credit_hours').value = subject.credit_hours || 3;
    document.getElementById('edit_subject_semester').value = subject.semester || 1;
    document.getElementById('edit_subject_description').value = subject.description || '';
    document.getElementById('edit_subject_status').value = subject.status || 'active';
    openModal('editSubjectModal');
}

// Edit Teacher
function editTeacher(teacher) {
    document.getElementById('edit_teacher_id').value = teacher.teacher_id;
    document.getElementById('edit_teacher_name').value = teacher.teacher_name;
    document.getElementById('edit_teacher_email').value = teacher.email;
    document.getElementById('edit_teacher_phone').value = teacher.phone_number || '';
    document.getElementById('edit_teacher_qualification').value = teacher.qualification || '';
    document.getElementById('edit_teacher_position_title').value = teacher.position_title || '';
    document.getElementById('edit_teacher_gender').value = teacher.gender || '';
    document.getElementById('edit_teacher_status').value = teacher.status;
    openModal('editTeacherModal');
}

// Delete functions
function deleteProgram(id, name) {
    document.querySelectorAll('.delete-id').forEach(field => field.value = '');
    document.getElementById('deleteAction').value = 'delete_program';
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteMessage').innerHTML = `Are you sure you want to delete program "<strong>${name}</strong>"?<br><br><span style="color: #C62828; font-size: 14px;">This action cannot be undone.</span>`;
    openModal('deleteModal');
}

function deleteClass(id, name) {
    document.querySelectorAll('.delete-id').forEach(field => field.value = '');
    document.getElementById('deleteAction').value = 'delete_class';
    document.getElementById('deleteClassId').value = id;
    document.getElementById('deleteMessage').innerHTML = `Are you sure you want to delete class "<strong>${name}</strong>"?<br><br><span style="color: #C62828; font-size: 14px;">This action cannot be undone.</span>`;
    openModal('deleteModal');
}

function deleteSubject(id, name) {
    document.querySelectorAll('.delete-id').forEach(field => field.value = '');
    document.getElementById('deleteAction').value = 'delete_subject';
    document.getElementById('deleteSubjectId').value = id;
    document.getElementById('deleteMessage').innerHTML = `Are you sure you want to delete subject "<strong>${name}</strong>"?<br><br><span style="color: #C62828; font-size: 14px;">This action cannot be undone.</span>`;
    openModal('deleteModal');
}

function deleteTeacher(id, name) {
    document.querySelectorAll('.delete-id').forEach(field => field.value = '');
    document.getElementById('deleteAction').value = 'delete_teacher';
    document.getElementById('deleteTeacherId').value = id;
    document.getElementById('deleteMessage').innerHTML = `Are you sure you want to delete teacher "<strong>${name}</strong>"?<br><br><span style="color: #C62828; font-size: 14px;">This action cannot be undone.</span>`;
    openModal('deleteModal');
}

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.opacity = '0';
        setTimeout(() => alert.style.display = 'none', 300);
    });
}, 5000);

// Add animation on scroll
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'translateY(0)';
        }
    });
}, { threshold: 0.1 });

document.querySelectorAll('.section, .stat-card, .quick-action-card').forEach(el => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(20px)';
    el.style.transition = 'all 0.6s ease';
    observer.observe(el);
});
</script>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>