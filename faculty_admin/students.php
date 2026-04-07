<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// Modified access control to allow both super_admin and faculty_admin
if (!isset($_SESSION['user']) || !in_array(strtolower($_SESSION['user']['role']), ['super_admin', 'faculty_admin'])) {
  header("Location: ../login.php");
  exit;
}

// Get current user role and ID for restrictions
$current_user_role = strtolower($_SESSION['user']['role'] ?? '');
$current_user_linked_id = $_SESSION['user']['linked_id'] ?? null;
$is_faculty_admin = ($current_user_role === 'faculty_admin');

$message = "";
$type = "";

/* ============================================================
   AJAX HANDLERS FOR HIERARCHY - LIKE ATTENDANCE SYSTEM
============================================================ */
if (isset($_GET['ajax'])) {
  header('Content-Type: application/json');
  
  // GET FACULTIES BY CAMPUS
  if ($_GET['ajax'] == 'get_faculties_by_campus') {
    $campus_id = $_GET['campus_id'] ?? 0;
    
    // If faculty admin, only return their faculty
    if ($is_faculty_admin && $current_user_linked_id) {
        $stmt = $pdo->prepare("
            SELECT f.faculty_id, f.faculty_name 
            FROM faculties f
            WHERE f.faculty_id = ? AND f.status = 'active'
        ");
        $stmt->execute([$current_user_linked_id]);
        $faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
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
    }
    
    echo json_encode(['status' => 'success', 'faculties' => $faculties]);
    exit;
  }
  
  // GET DEPARTMENTS BY FACULTY & CAMPUS
  if ($_GET['ajax'] == 'get_departments_by_faculty') {
    $faculty_id = $_GET['faculty_id'] ?? 0;
    $campus_id = $_GET['campus_id'] ?? 0;
    
    // If faculty admin, ensure they're only accessing their faculty
    if ($is_faculty_admin && $faculty_id != $current_user_linked_id) {
        echo json_encode(['status' => 'success', 'departments' => []]);
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
    
    echo json_encode(['status' => 'success', 'departments' => $departments]);
    exit;
  }
  
  // GET PROGRAMS BY DEPARTMENT, FACULTY & CAMPUS
  if ($_GET['ajax'] == 'get_programs_by_department') {
    $department_id = $_GET['department_id'] ?? 0;
    $faculty_id = $_GET['faculty_id'] ?? 0;
    $campus_id = $_GET['campus_id'] ?? 0;
    
    // If faculty admin, ensure they're only accessing their faculty
    if ($is_faculty_admin && $faculty_id != $current_user_linked_id) {
        echo json_encode(['status' => 'success', 'programs' => []]);
        exit;
    }
    
    $stmt = $pdo->prepare("
      SELECT program_id, program_name 
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
  
  // GET STUDY GROUPS BY PROGRAM, DEPARTMENT, FACULTY & CAMPUS WITH STUDY MODE
  if ($_GET['ajax'] == 'get_study_groups_by_program') {
    $program_id = $_GET['program_id'] ?? 0;
    $department_id = $_GET['department_id'] ?? 0;
    $faculty_id = $_GET['faculty_id'] ?? 0;
    $campus_id = $_GET['campus_id'] ?? 0;
    
    // If faculty admin, ensure they're only accessing their faculty
    if ($is_faculty_admin && $faculty_id != $current_user_linked_id) {
        echo json_encode(['status' => 'success', 'study_groups' => []]);
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
    $study_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['status' => 'success', 'study_groups' => $study_groups]);
    exit;
  }
  
  // GET PARENT INFO
  elseif ($_GET['ajax'] === 'get_parent') {
    $name = trim($_GET['name'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM parents WHERE full_name LIKE ? LIMIT 1");
    $stmt->execute(["%$name%"]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
    exit;
  }
}

/* ============================================================
   FILTER SYSTEM - LIKE ATTENDANCE
============================================================ */
$filter_campus = $_GET['filter_campus'] ?? '';
$filter_faculty = $_GET['filter_faculty'] ?? '';
$filter_department = $_GET['filter_department'] ?? '';
$filter_program = $_GET['filter_program'] ?? '';
$filter_study_group = $_GET['filter_study_group'] ?? '';
$filter_study_mode = $_GET['filter_study_mode'] ?? '';
$filter_status = $_GET['filter_status'] ?? '';
$search = trim($_GET['q'] ?? '');

// Build WHERE conditions for filter
$whereConditions = [];
$params = [];

// RESTRICTION FOR FACULTY ADMIN - Only see their faculty's students
if ($is_faculty_admin && $current_user_linked_id) {
    $whereConditions[] = "s.faculty_id = ?";
    $params[] = $current_user_linked_id;
    
    // Force faculty filter for faculty admin
    $filter_faculty = $current_user_linked_id;
}

// Search condition
if (!empty($search)) {
  $whereConditions[] = "(s.full_name LIKE ? OR s.reg_no LIKE ?)";
  $params[] = "%$search%";
  $params[] = "%$search%";
}

// Filter conditions
if (!empty($filter_campus)) {
  $whereConditions[] = "s.campus_id = ?";
  $params[] = $filter_campus;
}

if (!empty($filter_faculty) && !$is_faculty_admin) { // Faculty admin already has faculty filter
  $whereConditions[] = "s.faculty_id = ?";
  $params[] = $filter_faculty;
}

if (!empty($filter_department)) {
  $whereConditions[] = "s.department_id = ?";
  $params[] = $filter_department;
}

if (!empty($filter_program)) {
  $whereConditions[] = "s.program_id = ?";
  $params[] = $filter_program;
}

if (!empty($filter_study_group)) {
  $whereConditions[] = "s.class_id = ?";
  $params[] = $filter_study_group;
}

if (!empty($filter_study_mode)) {
  $whereConditions[] = "cl.study_mode = ?";
  $params[] = $filter_study_mode;
}

if (!empty($filter_status)) {
  $whereConditions[] = "s.status = ?";
  $params[] = $filter_status;
}

/* ============================================================
   CSV IMPORT / EXPORT + CRUD (Students + Parents)
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

  /* ============================================================
     ✅ EXPORT CSV
  ============================================================ */
  if ($_POST['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=students_export_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
  
    // ✅ Header row
    fputcsv($output, [
      'Reg No', 'Full Name', 'Gender', 'DOB', 'Email', 'Phone', 'Address',
      'Campus', 'Faculty', 'Department', 'Program', 'Study Group', 'Study Mode', 'Semester',
      'Status', 'Guardian Name', 'Guardian Gender', 'Guardian Phone', 'Guardian Email',
      'Guardian Address', 'Guardian Occupation', 'Guardian Status', 'Relation'
    ]);
  
    // ✅ Fetch data with name joins and faculty admin restriction
    $sql = "
      SELECT 
        s.reg_no,
        s.full_name,
        s.gender,
        s.dob,
        s.email,
        s.phone_number,
        s.address,
        COALESCE(c.campus_name, 'N/A') AS campus_name,
        COALESCE(f.faculty_name, 'N/A') AS faculty_name,
        COALESCE(d.department_name, 'N/A') AS department_name,
        COALESCE(pr.program_name, 'N/A') AS program_name,
        COALESCE(cl.class_name, 'N/A') AS class_name,
        COALESCE(cl.study_mode, 'N/A') AS study_mode,
        COALESCE(se.semester_name, 'N/A') AS semester_name,
        s.status,
        COALESCE(p.full_name, 'N/A') AS guardian_name,
        COALESCE(p.gender, 'N/A') AS guardian_gender,
        COALESCE(p.phone, 'N/A') AS guardian_phone,
        COALESCE(p.email, 'N/A') AS guardian_email,
        COALESCE(p.address, 'N/A') AS guardian_address,
        COALESCE(p.occupation, 'N/A') AS guardian_occupation,
        COALESCE(p.status, 'N/A') AS guardian_status,
        COALESCE(ps.relation_type, 'N/A') AS relation_type
      FROM students s
      LEFT JOIN parents p ON s.parent_id = p.parent_id
      LEFT JOIN parent_student ps ON s.student_id = ps.student_id
      LEFT JOIN campus c ON s.campus_id = c.campus_id
      LEFT JOIN faculties f ON s.faculty_id = f.faculty_id
      LEFT JOIN departments d ON s.department_id = d.department_id
      LEFT JOIN programs pr ON s.program_id = pr.program_id
      LEFT JOIN classes cl ON s.class_id = cl.class_id
      LEFT JOIN semester se ON s.semester_id = se.semester_id
    ";
    
    if ($is_faculty_admin && $current_user_linked_id) {
        $sql .= " WHERE s.faculty_id = " . intval($current_user_linked_id);
    }
    
    $sql .= " ORDER BY s.student_id DESC";
    
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  
    // ✅ Write each row
    foreach ($rows as $r) {
      foreach ($r as &$v) {
        if (is_null($v) || $v === '') $v = 'N/A';
      }
      fputcsv($output, $r);
    }
  
    fclose($output);
    exit;
  }

  /* ============================================================
     ✅ IMPORT CSV (Only super_admin can import)
  ============================================================ */
  if ($_POST['action'] === 'import_csv' && !empty($_FILES['csv_file']['tmp_name'])) {
    
    // Only super_admin can import
    if (!$is_faculty_admin) {
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, 'r');
      
        if ($handle !== false) {
          $header = fgetcsv($handle);
          $inserted = 0;
      
          while (($row = fgetcsv($handle)) !== false) {
            $row = array_pad($row, 23, '');
            list(
              $reg_no, $full_name, $gender, $dob, $email, $phone, $address,
              $campus_name, $faculty_name, $department_name, $program_name, $study_group_name, $study_mode, $semester_name,
              $status_csv, $g_name, $g_gender, $g_phone, $g_email, $g_address,
              $g_occupation, $g_status_csv, $relation
            ) = array_map('trim', $row);
      
            if (empty($reg_no) || empty($full_name)) continue;
      
            $status = ($status_csv && strtolower($status_csv) === 'inactive') ? 'inactive' : 'active';
            $g_status = ($g_status_csv && strtolower($g_status_csv) === 'inactive') ? 'inactive' : 'active';
      
            /* ================= CAMPUS ================= */
            $campus_id = null;
            if (!empty($campus_name) && strtolower($campus_name) !== 'select' && strtolower($campus_name) !== 'n/a') {
              $q = $pdo->prepare("SELECT campus_id FROM campus WHERE campus_name=? LIMIT 1");
              $q->execute([$campus_name]);
              $campus_id = $q->fetchColumn();
              if (!$campus_id) {
                $pdo->prepare("INSERT INTO campus (campus_name) VALUES (?)")->execute([$campus_name]);
                $campus_id = $pdo->lastInsertId();
              }
            }
      
            /* ================= FACULTY ================= */
            $faculty_id = null;
            if (!empty($faculty_name) && strtolower($faculty_name) !== 'select' && strtolower($faculty_name) !== 'n/a') {
              $q = $pdo->prepare("SELECT faculty_id FROM faculties WHERE faculty_name=? LIMIT 1");
              $q->execute([$faculty_name]);
              $faculty_id = $q->fetchColumn();
              
              if (!$faculty_id) {
                $pdo->prepare("INSERT INTO faculties (faculty_name) VALUES (?)")->execute([$faculty_name]);
                $faculty_id = $pdo->lastInsertId();
              }
              
              if ($campus_id) {
                $checkLink = $pdo->prepare("SELECT COUNT(*) FROM faculty_campus WHERE faculty_id=? AND campus_id=?");
                $checkLink->execute([$faculty_id, $campus_id]);
                if (!$checkLink->fetchColumn()) {
                  $pdo->prepare("INSERT INTO faculty_campus (faculty_id, campus_id) VALUES (?, ?)")
                      ->execute([$faculty_id, $campus_id]);
                }
              }
            }
      
            /* ================= DEPARTMENT ================= */
            $department_id = null;
            if (!empty($department_name) && strtolower($department_name) !== 'select' && strtolower($department_name) !== 'n/a') {
              $q = $pdo->prepare("SELECT department_id FROM departments WHERE department_name=? AND faculty_id=? AND campus_id=? LIMIT 1");
              $q->execute([$department_name, $faculty_id, $campus_id]);
              $department_id = $q->fetchColumn();
              
              if (!$department_id && $faculty_id && $campus_id) {
                $pdo->prepare("INSERT INTO departments (department_name, faculty_id, campus_id) VALUES (?, ?, ?)")
                    ->execute([$department_name, $faculty_id, $campus_id]);
                $department_id = $pdo->lastInsertId();
              }
            }
      
            /* ================= PROGRAM ================= */
            $program_id = null;
            if (!empty($program_name) && strtolower($program_name) !== 'select' && strtolower($program_name) !== 'n/a') {
              $q = $pdo->prepare("SELECT program_id FROM programs WHERE program_name=? AND department_id=? AND faculty_id=? AND campus_id=? LIMIT 1");
              $q->execute([$program_name, $department_id, $faculty_id, $campus_id]);
              $program_id = $q->fetchColumn();
              
              if (!$program_id && $department_id && $faculty_id && $campus_id) {
                $pdo->prepare("INSERT INTO programs (program_name, department_id, faculty_id, campus_id) VALUES (?, ?, ?, ?)")
                    ->execute([$program_name, $department_id, $faculty_id, $campus_id]);
                $program_id = $pdo->lastInsertId();
              }
            }
      
            /* ================= STUDY GROUP ================= */
            $class_id = null;
            if (!empty($study_group_name) && strtolower($study_group_name) !== 'select' && strtolower($study_group_name) !== 'n/a') {
              $study_mode_db = !empty($study_mode) ? $study_mode : 'fulltime';
              
              $q = $pdo->prepare("
                SELECT class_id FROM classes 
                WHERE class_name=? 
                AND program_id=? 
                AND department_id=? 
                AND faculty_id=? 
                AND campus_id=?
                AND study_mode=?
                LIMIT 1
              ");
              $q->execute([$study_group_name, $program_id, $department_id, $faculty_id, $campus_id, $study_mode_db]);
              $class_id = $q->fetchColumn();
              
              if (!$class_id && $program_id && $department_id && $faculty_id && $campus_id) {
                $pdo->prepare("
                  INSERT INTO classes (class_name, study_mode, program_id, department_id, faculty_id, campus_id) 
                  VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$study_group_name, $study_mode_db, $program_id, $department_id, $faculty_id, $campus_id]);
                $class_id = $pdo->lastInsertId();
              }
            }
      
            /* ================= SEMESTER ================= */
            $semester_id = null;
            if (!empty($semester_name) && strtolower($semester_name) !== 'select' && strtolower($semester_name) !== 'n/a') {
              $q = $pdo->prepare("SELECT semester_id FROM semester WHERE semester_name=? LIMIT 1");
              $q->execute([$semester_name]);
              $semester_id = $q->fetchColumn();
              if (!$semester_id) {
                $pdo->prepare("INSERT INTO semester (semester_name) VALUES (?)")->execute([$semester_name]);
                $semester_id = $pdo->lastInsertId();
              }
            }
      
            /* ================= PARENT ================= */
            $parent_id = null;
            $g_phone_clean = trim($g_phone);
      
            if (!empty($g_phone_clean) && $g_phone_clean !== 'N/A') {
              $q = $pdo->prepare("SELECT parent_id FROM parents WHERE phone=? LIMIT 1");
              $q->execute([$g_phone_clean]);
              $parent_id = $q->fetchColumn();
            }
      
            if (!$parent_id && !empty($g_name) && $g_name !== 'N/A') {
              $q = $pdo->prepare("SELECT parent_id FROM parents WHERE full_name=? LIMIT 1");
              $q->execute([$g_name]);
              $parent_id = $q->fetchColumn();
            }
      
            if (!$parent_id) {
              $phone_for_db = (!empty($g_phone_clean) && $g_phone_clean !== 'N/A') ? $g_phone_clean : null;
              $email_for_parent = (!empty($g_email) && $g_email !== 'N/A') ? $g_email : strtolower(str_replace(' ', '', $g_name ?: 'parent')) . '@example.com';
      
              $chkP = $pdo->prepare("SELECT COUNT(*) FROM parents WHERE email=?");
              $chkP->execute([$email_for_parent]);
              if ($chkP->fetchColumn()) {
                $email_for_parent = uniqid('parent_') . '@example.com';
              }
      
              $pdo->prepare("
                INSERT INTO parents (full_name, gender, phone, email, address, occupation, status, created_at)
                VALUES (?,?,?,?,?,?,?,NOW())
              ")->execute([
                $g_name ?: 'Unknown Parent',
                ($g_gender && $g_gender !== 'N/A') ? $g_gender : 'N/A',
                $phone_for_db,
                $email_for_parent,
                ($g_address && $g_address !== 'N/A') ? $g_address : '',
                ($g_occupation && $g_occupation !== 'N/A') ? $g_occupation : '',
                $g_status
              ]);
              $parent_id = $pdo->lastInsertId();
            }
      
            /* ================= STUDENT ================= */
            $chk = $pdo->prepare("SELECT COUNT(*) FROM students WHERE reg_no=?");
            $chk->execute([$reg_no]);
      
            if (!$chk->fetchColumn()) {
              $email_clean = trim($email);
              if (empty($email_clean) || $email_clean === 'N/A') {
                $email_clean = null;
              } else {
                $chkEmail = $pdo->prepare("SELECT COUNT(*) FROM students WHERE email=?");
                $chkEmail->execute([$email_clean]);
                if ($chkEmail->fetchColumn()) {
                  $email_clean = uniqid('student_') . '@example.com';
                }
              }
      
              $pdo->prepare("
                INSERT INTO students
                (student_uuid, parent_id, campus_id, faculty_id, department_id, program_id,
                 reg_no, full_name, gender, dob, phone_number, email, address,
                 class_id, semester_id, status, created_at)
                VALUES (UUID(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
              ")->execute([
                $parent_id,
                $campus_id, $faculty_id, $department_id, $program_id,
                $reg_no, $full_name, ($gender && $gender !== 'N/A') ? $gender : 'N/A',
                (!empty($dob) && $dob !== 'N/A') ? $dob : '2000-01-01',
                (!empty($phone) && $phone !== 'N/A') ? $phone : '',
                $email_clean,
                (!empty($address) && $address !== 'N/A') ? $address : '',
                $class_id, $semester_id, $status
              ]);
      
              $sid = $pdo->lastInsertId();
      
              $pdo->prepare("
                INSERT INTO parent_student (parent_id, student_id, relation_type, created_at)
                VALUES (?,?,?,NOW())
              ")->execute([$parent_id, $sid, ($relation && $relation !== 'N/A') ? $relation : 'guardian']);
      
              $inserted++;
            }
          }
      
          fclose($handle);
          $message = "✅ Imported $inserted student(s) successfully!";
          $type = "success";
        } else {
          $message = "❌ Unable to read CSV file!";
          $type = "error";
        }
    } else {
        $message = "❌ Faculty admins cannot import CSV files!";
        $type = "error";
    }
  }
}

/* ============================================================
   ✅ CRUD OPERATIONS (Add / Update / Delete)
============================================================ */
if (
  $_SERVER['REQUEST_METHOD'] === 'POST' &&
  isset($_POST['action']) &&
  !in_array($_POST['action'], ['export_csv', 'import_csv'])
) {
  try {
    $pdo->beginTransaction();

    if ($_POST['action'] === 'delete') {
      // Check if faculty admin has permission to delete this student
      if ($is_faculty_admin && $current_user_linked_id) {
          $check = $pdo->prepare("SELECT faculty_id FROM students WHERE student_id=?");
          $check->execute([$_POST['student_id']]);
          $student_faculty = $check->fetchColumn();
          
          if ($student_faculty != $current_user_linked_id) {
              throw new Exception("You don't have permission to delete this student!");
          }
      }
      
      $pdo->prepare("DELETE FROM users WHERE linked_id=? AND linked_table='student'")
          ->execute([$_POST['student_id']]);
      $pdo->prepare("DELETE FROM students WHERE student_id=?")
          ->execute([$_POST['student_id']]);
      $message = "🗑️ Student deleted successfully!";
    } else {
      if (empty($_POST['reg_no']) || empty($_POST['full_name']) || empty($_POST['guardian_name'])) {
        throw new Exception("Missing required fields!");
      }

      if ($_POST['action'] === 'add') {
        $check = $pdo->prepare("SELECT COUNT(*) FROM students WHERE reg_no=?");
        $check->execute([$_POST['reg_no']]);
        if ($check->fetchColumn() > 0) {
          throw new Exception("❌ Registration number already exists!");
        }
      }

      if (empty($_POST['campus_id'])) {
        throw new Exception("❌ Campus is required!");
      }

      // For faculty admin, force their faculty_id
      if ($is_faculty_admin && $current_user_linked_id) {
          $_POST['faculty_id'] = $current_user_linked_id;
      }

      $faculty_id = $_POST['faculty_id'] ?: null;
      if ($faculty_id && $_POST['campus_id']) {
        $checkFacultyCampus = $pdo->prepare("SELECT COUNT(*) FROM faculty_campus WHERE faculty_id=? AND campus_id=?");
        $checkFacultyCampus->execute([$faculty_id, $_POST['campus_id']]);
        if (!$checkFacultyCampus->fetchColumn()) {
          $pdo->prepare("INSERT INTO faculty_campus (faculty_id, campus_id) VALUES (?, ?)")
              ->execute([$faculty_id, $_POST['campus_id']]);
        }
      }

      $q = $pdo->prepare("SELECT * FROM parents WHERE full_name=? OR phone=? LIMIT 1");
      $q->execute([$_POST['guardian_name'], $_POST['guardian_phone']]);
      $p = $q->fetch(PDO::FETCH_ASSOC);
      $pid = $p['parent_id'] ?? null;

      if (!$pid) {
        $pdo->prepare("
          INSERT INTO parents (full_name, gender, phone, email, address, occupation, status, created_at)
          VALUES (?,?,?,?,?,?,?,NOW())
        ")->execute([
          $_POST['guardian_name'],
          $_POST['guardian_gender'],
          $_POST['guardian_phone'],
          $_POST['guardian_email'] ?: strtolower(str_replace(' ', '', $_POST['guardian_name'])) . '@example.com',
          $_POST['guardian_address'],
          $_POST['guardian_occupation'],
          $_POST['guardian_status'] ?? 'active'
        ]);
        $pid = $pdo->lastInsertId();
      }

      $checkParent = $pdo->prepare("SELECT user_id FROM users WHERE linked_id=? AND linked_table='parent'");
      $checkParent->execute([$pid]);
      if (!$checkParent->fetch()) {
        $plainPass = '123';
        $pdo->prepare("
          INSERT INTO users 
          (user_uuid, username, email, phone_number, profile_photo_path, password, password_plain, role, linked_id, linked_table, status, created_at)
          VALUES (UUID(),?,?,?,?,?,?,?,?,?,'active',NOW())
        ")->execute([
          $_POST['guardian_name'],
          $_POST['guardian_email'] ?: strtolower(str_replace(' ', '', $_POST['guardian_name'])) . '@example.com',
          $_POST['guardian_phone'],
          'upload/profiles/default.png',
          password_hash($plainPass, PASSWORD_BCRYPT),
          $plainPass,
          'parent',
          $pid,
          'parent'
        ]);
      }

      $photo = $_POST['existing_photo'] ?? null;
      if (!empty($_FILES['photo']['name'])) {
        $dir = __DIR__ . '/../upload/profiles/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $name = uniqid('stu_') . ".$ext";
        $photo = "upload/profiles/$name";
        move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__ . '/../' . $photo);
      }

      $rel = $_POST['guardian_relation'] ?? 'guardian';
      $status = $_POST['status'] ?? 'active';
      $study_mode = $_POST['study_mode'] ?? 'Full-Time';

      $class_id = $_POST['class_id'] ?: null;
      if ($class_id && $_POST['program_id'] && $_POST['campus_id']) {
        $checkClass = $pdo->prepare("
          SELECT class_id FROM classes 
          WHERE class_id=? 
          AND program_id=? 
          AND department_id=? 
          AND faculty_id=? 
          AND campus_id=?
        ");
        $checkClass->execute([
          $class_id, 
          $_POST['program_id'], 
          $_POST['department_id'], 
          $_POST['faculty_id'], 
          $_POST['campus_id']
        ]);
        
        if (!$checkClass->fetchColumn()) {
          $pdo->prepare("
            INSERT INTO classes (class_name, study_mode, program_id, department_id, faculty_id, campus_id) 
            SELECT class_name, ?, ?, ?, ?, ? FROM classes WHERE class_id=?
          ")->execute([
            $study_mode,
            $_POST['program_id'], 
            $_POST['department_id'], 
            $_POST['faculty_id'], 
            $_POST['campus_id'], 
            $class_id
          ]);
          $class_id = $pdo->lastInsertId();
        } else {
          $pdo->prepare("
            UPDATE classes 
            SET program_id=?, department_id=?, faculty_id=?, campus_id=?, study_mode=?, updated_at=NOW() 
            WHERE class_id=?
          ")->execute([
            $_POST['program_id'], 
            $_POST['department_id'], 
            $_POST['faculty_id'], 
            $_POST['campus_id'], 
            $study_mode,
            $class_id
          ]);
        }
      }

      if ($_POST['action'] === 'add') {
        $stmt = $pdo->prepare("
          INSERT INTO students 
          (student_uuid, parent_id, campus_id, faculty_id, department_id, program_id,
           reg_no, full_name, gender, dob, phone_number, email, address,
           photo_path, class_id, semester_id, status, created_at)
          VALUES (UUID(),?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ");

        $stmt->execute([
          $pid,
          $_POST['campus_id'] ?: null,
          $_POST['faculty_id'] ?: null,
          $_POST['department_id'] ?: null,
          $_POST['program_id'] ?: null,
          $_POST['reg_no'],
          $_POST['full_name'],
          $_POST['gender'],
          $_POST['dob'] ?: null,
          $_POST['phone_number'] ?: null,
          $_POST['email'] ?: null,
          $_POST['address'] ?: null,
          $photo,
          $class_id,
          $_POST['semester_id'] ?: null,
          $status
        ]);

        $sid = $pdo->lastInsertId();

        $pdo->prepare("
          INSERT INTO parent_student (parent_id, student_id, relation_type, created_at)
          VALUES (?,?,?,NOW())
        ")->execute([$pid, $sid, $rel]);

        $plainPass = '123';
        $pdo->prepare("
          INSERT INTO users 
          (user_uuid, username, email, phone_number, profile_photo_path, password, password_plain, role, linked_id, linked_table, status, created_at)
          VALUES (UUID(),?,?,?,?,?,?,?,?,?,'active',NOW())
        ")->execute([
          $_POST['reg_no'],
          $_POST['email'] ?: strtolower(str_replace(' ', '', $_POST['full_name'])) . '@example.com',
          $_POST['phone_number'] ?: null,
          $photo ?: 'upload/profiles/default.png',
          password_hash($plainPass, PASSWORD_BCRYPT),
          $plainPass,
          'student',
          $sid,
          'student'
        ]);

        $message = "✅ Student Added Successfully!";
      } elseif ($_POST['action'] === 'update') {
        // Check if faculty admin has permission to update this student
        if ($is_faculty_admin && $current_user_linked_id) {
            $check = $pdo->prepare("SELECT faculty_id FROM students WHERE student_id=?");
            $check->execute([$_POST['student_id']]);
            $student_faculty = $check->fetchColumn();
            
            if ($student_faculty != $current_user_linked_id) {
                throw new Exception("You don't have permission to update this student!");
            }
        }
        
        $stmt = $pdo->prepare("
          UPDATE students SET 
            parent_id=?, campus_id=?, faculty_id=?, department_id=?, program_id=?, 
            reg_no=?, full_name=?, gender=?, dob=?, phone_number=?, email=?, address=?, 
            photo_path=?, class_id=?, semester_id=?, status=? 
          WHERE student_id=?
        ");

        $stmt->execute([
          $pid,
          $_POST['campus_id'] ?: null,
          $_POST['faculty_id'] ?: null,
          $_POST['department_id'] ?: null,
          $_POST['program_id'] ?: null,
          $_POST['reg_no'],
          $_POST['full_name'],
          $_POST['gender'],
          $_POST['dob'] ?: null,
          $_POST['phone_number'] ?: null,
          $_POST['email'] ?: null,
          $_POST['address'] ?: null,
          $photo,
          $class_id,
          $_POST['semester_id'] ?: null,
          $status,
          $_POST['student_id']
        ]);

        $pdo->prepare("
          REPLACE INTO parent_student (parent_id, student_id, relation_type, created_at)
          VALUES (?,?,?,NOW())
        ")->execute([$pid, $_POST['student_id'], $rel]);

        $chkStu = $pdo->prepare("SELECT user_id FROM users WHERE linked_id=? AND linked_table='student'");
        $chkStu->execute([$_POST['student_id']]);
        if (!$chkStu->fetch()) {
          $plainPass = '123';
          $pdo->prepare("
            INSERT INTO users 
            (user_uuid, username, email, phone_number, profile_photo_path, password, password_plain, role, linked_id, linked_table, status, created_at)
            VALUES (UUID(),?,?,?,?,?,?,?,?,?,'active',NOW())
          ")->execute([
            $_POST['reg_no'],
            $_POST['email'] ?: strtolower(str_replace(' ', '', $_POST['full_name'])) . '@example.com',
            $_POST['phone_number'] ?: null,
            $photo ?: 'upload/profiles/default.png',
            password_hash($plainPass, PASSWORD_BCRYPT),
            $plainPass,
            'student',
            $_POST['student_id'],
            'student'
          ]);
        }

        $pdo->prepare("UPDATE users SET status=? WHERE linked_id=? AND linked_table='student'")
             ->execute([$status, $_POST['student_id']]);

        $message = "✏️ Student Updated Successfully!";
      }
    }

    $pdo->commit();
    $type = "success";
  } catch (Exception $e) {
    $pdo->rollBack();
    $message = "❌ " . $e->getMessage();
    $type = "error";
  }
}

/* ============================================================
   FETCH DATA WITH FILTERS
============================================================ */

// Get campuses based on user role
if ($is_faculty_admin && $current_user_linked_id) {
    $stmt = $pdo->prepare("
        SELECT c.* FROM campus c
        JOIN faculty_campus fc ON c.campus_id = fc.campus_id
        WHERE fc.faculty_id = ? AND c.status = 'active'
        ORDER BY c.campus_name ASC
    ");
    $stmt->execute([$current_user_linked_id]);
    $campuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $campuses = $pdo->query("SELECT * FROM campus WHERE status='active' ORDER BY campus_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$semesters = $pdo->query("SELECT * FROM semester WHERE status='active' ORDER BY semester_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get filter options based on selected values
$filter_faculties = [];

if ($is_faculty_admin && $current_user_linked_id) {
    // Faculty admin only sees their own faculty
    $stmt = $pdo->prepare("
        SELECT faculty_id, faculty_name 
        FROM faculties 
        WHERE faculty_id = ? AND status = 'active'
    ");
    $stmt->execute([$current_user_linked_id]);
    $filter_faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($filter_campus)) {
    // Super admin sees faculties by campus
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.faculty_id, f.faculty_name 
        FROM faculties f
        JOIN faculty_campus fc ON f.faculty_id = fc.faculty_id
        WHERE fc.campus_id = ?
        AND f.status = 'active'
        ORDER BY f.faculty_name
    ");
    $stmt->execute([$filter_campus]);
    $filter_faculties = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$filter_departments = [];
if (!empty($filter_faculty) && !empty($filter_campus)) {
  $stmt = $pdo->prepare("
    SELECT department_id, department_name 
    FROM departments 
    WHERE faculty_id = ? 
    AND campus_id = ?
    AND status = 'active'
    ORDER BY department_name
  ");
  $stmt->execute([$filter_faculty, $filter_campus]);
  $filter_departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$filter_programs = [];
if (!empty($filter_department) && !empty($filter_faculty) && !empty($filter_campus)) {
  $stmt = $pdo->prepare("
    SELECT program_id, program_name 
    FROM programs 
    WHERE department_id = ? 
    AND faculty_id = ?
    AND campus_id = ?
    AND status = 'active'
    ORDER BY program_name
  ");
  $stmt->execute([$filter_department, $filter_faculty, $filter_campus]);
  $filter_programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$filter_study_groups = [];
if (!empty($filter_program) && !empty($filter_department) && !empty($filter_faculty) && !empty($filter_campus)) {
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
  $stmt->execute([$filter_program, $filter_department, $filter_faculty, $filter_campus]);
  $filter_study_groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Build main query with filters
$sql = "
  SELECT s.*, p.full_name AS parent_name, p.phone AS parent_phone, ps.relation_type,
    p.gender AS guardian_gender, p.email AS guardian_email, p.address AS guardian_address,
    p.occupation AS guardian_occupation, p.status AS guardian_status,
    u.password AS student_password,
    c.campus_name,
    f.faculty_name,
    d.department_name,
    pr.program_name,
    cl.class_name,
    cl.study_mode,
    se.semester_name
  FROM students s
  LEFT JOIN parents p ON s.parent_id = p.parent_id
  LEFT JOIN parent_student ps ON s.student_id = ps.student_id
  LEFT JOIN users u ON s.student_id = u.linked_id AND u.linked_table = 'student'
  LEFT JOIN campus c ON s.campus_id = c.campus_id
  LEFT JOIN faculties f ON s.faculty_id = f.faculty_id
  LEFT JOIN departments d ON s.department_id = d.department_id
  LEFT JOIN programs pr ON s.program_id = pr.program_id
  LEFT JOIN classes cl ON s.class_id = cl.class_id
  LEFT JOIN semester se ON s.semester_id = se.semester_id
";

if (!empty($whereConditions)) {
  $sql .= " WHERE " . implode(" AND ", $whereConditions);
}

$sql .= " ORDER BY s.student_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts for statistics with faculty admin restrictions
if ($is_faculty_admin && $current_user_linked_id) {
    $total_students = $pdo->prepare("SELECT COUNT(*) FROM students WHERE faculty_id = ?");
    $total_students->execute([$current_user_linked_id]);
    $total_students = $total_students->fetchColumn();
    
    $active_students = $pdo->prepare("SELECT COUNT(*) FROM students WHERE faculty_id = ? AND status = 'active'");
    $active_students->execute([$current_user_linked_id]);
    $active_students = $active_students->fetchColumn();
    
    $inactive_students = $pdo->prepare("SELECT COUNT(*) FROM students WHERE faculty_id = ? AND status = 'inactive'");
    $inactive_students->execute([$current_user_linked_id]);
    $inactive_students = $inactive_students->fetchColumn();
} else {
    $total_students = $pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
    $active_students = $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'active'")->fetchColumn();
    $inactive_students = $pdo->query("SELECT COUNT(*) FROM students WHERE status = 'inactive'")->fetchColumn();
}

include('../includes/header.php');
?>

<div class="main-content">
  <?php if($message): ?>
  <div class="alert <?= $type=='success'?'alert-success':'alert-error' ?>"><?= htmlspecialchars($message) ?></div>
  <script>setTimeout(()=>document.querySelector('.alert').classList.add('hide'),3000);</script>
  <?php endif; ?>

  <!-- STATISTICS CARDS -->
  <!-- <div class="stats-cards">
    <div class="card">
      <h3><?= $total_students ?></h3>
      <p>Total Students</p>
    </div>
    <div class="card">
      <h3 style="color:#00843D;"><?= $active_students ?></h3>
      <p>Active Students</p>
    </div>
    <div class="card">
      <h3 style="color:#C62828;"><?= $inactive_students ?></h3>
      <p>Inactive Students</p>
    </div>
  </div> -->

  <div class="top-bar">
    <h2>Students Management <?= $is_faculty_admin ? '(Faculty Admin View)' : '' ?></h2>
    <div>
      <?php if(!$is_faculty_admin): // Only show import for super admin ?>
      <form method="POST" enctype="multipart/form-data" style="display:inline;">
        <input type="file" name="csv_file" accept=".csv" required>
        <input type="hidden" name="action" value="import_csv">
        <button class="btn blue">Import CSV</button>
      </form>
      <?php endif; ?>
      
      <form method="POST" style="display:inline;">
        <input type="hidden" name="action" value="export_csv">
        <button class="btn green">Export CSV</button>
      </form>
      <button class="btn green" onclick="openModal('addModal')">+ Add Student</button>
    </div>
  </div>

  <!-- FILTER FORM - LIKE ATTENDANCE SYSTEM -->
  <div class="filter-box">
    <form method="GET" id="filterForm">
      <div class="grid">
        <!-- Campus -->
        <div>
          <label>Campus</label>
          <select name="filter_campus" id="filter_campus" onchange="onFilterCampusChange(this.value)">
            <option value="">All Campuses</option>
            <?php foreach($campuses as $c): ?>
              <option value="<?= $c['campus_id'] ?>" <?= $filter_campus==$c['campus_id']?'selected':'' ?>>
                <?= htmlspecialchars($c['campus_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Faculty -->
        <div>
          <label>Faculty</label>
          <select name="filter_faculty" id="filter_faculty" onchange="onFilterFacultyChange(this.value)" 
            <?php 
            if ($is_faculty_admin) {
                echo 'disabled';
            } elseif (empty($filter_faculties)) {
                echo 'disabled';
            }
            ?>>
            <option value="">All Faculties</option>
            <?php foreach($filter_faculties as $f): ?>
              <option value="<?= $f['faculty_id'] ?>" <?= $filter_faculty==$f['faculty_id']?'selected':'' ?>>
                <?= htmlspecialchars($f['faculty_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Department -->
        <div>
          <label>Department</label>
          <select name="filter_department" id="filter_department" onchange="onFilterDepartmentChange(this.value)" <?= empty($filter_departments)?'disabled':'' ?>>
            <option value="">All Departments</option>
            <?php foreach($filter_departments as $d): ?>
              <option value="<?= $d['department_id'] ?>" <?= $filter_department==$d['department_id']?'selected':'' ?>>
                <?= htmlspecialchars($d['department_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Program -->
        <div>
          <label>Program</label>
          <select name="filter_program" id="filter_program" onchange="onFilterProgramChange(this.value)" <?= empty($filter_programs)?'disabled':'' ?>>
            <option value="">All Programs</option>
            <?php foreach($filter_programs as $p): ?>
              <option value="<?= $p['program_id'] ?>" <?= $filter_program==$p['program_id']?'selected':'' ?>>
                <?= htmlspecialchars($p['program_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Study Group -->
        <div>
          <label>Study Group</label>
          <select name="filter_study_group" id="filter_study_group" <?= empty($filter_study_groups)?'disabled':'' ?>>
            <option value="">All Study Groups</option>
            <?php foreach($filter_study_groups as $sg): ?>
              <option value="<?= $sg['class_id'] ?>" <?= $filter_study_group==$sg['class_id']?'selected':'' ?>>
                <?= htmlspecialchars($sg['class_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Study Mode -->
        <div>
          <label>Study Mode</label>
          <select name="filter_study_mode">
            <option value="">All Modes</option>
            <option value="Full-Time" <?= $filter_study_mode=='Full-Time'?'selected':'' ?>>Full-Time</option>
            <option value="Part-Time" <?= $filter_study_mode=='Part-Time'?'selected':'' ?>>Part-Time</option>
          </select>
        </div>

        <!-- Status -->
        <div>
          <label>Status</label>
          <select name="filter_status">
            <option value="">All Status</option>
            <option value="active" <?= $filter_status=='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $filter_status=='inactive'?'selected':'' ?>>Inactive</option>
          </select>
        </div>

        <!-- Search -->
        <div>
          <label>Search</label>
          <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Name or Reg No...">
        </div>

        <!-- Buttons -->
        <div style="align-self:end; display:flex; gap:8px;">
          <button type="submit" class="btn blue">
            <i class="fa fa-filter"></i> Filter
          </button>
          <button type="button" class="btn gray" onclick="resetFilter()">
            <i class="fa fa-refresh"></i> Reset
          </button>
        </div>
      </div>
    </form>
  </div>

  <div class="table-responsive">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Reg No</th>
          <th>Name</th>
          <th>Campus</th>
          <th>Faculty</th>
          <th>Department</th>
          <th>Study Group (Mode)</th>
          <th>Parent</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if(empty($students)): ?>
          <tr>
            <td colspan="10" style="text-align:center; padding:20px; color:#666;">
              No students found. <?= !empty($whereConditions)?'Try changing your filters.':'Add your first student!' ?>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach($students as $i=>$s): ?>
          <tr>
            <td><?= $i+1 ?></td>
            <td><strong><?= htmlspecialchars($s['reg_no']) ?></strong></td>
            <td><?= htmlspecialchars($s['full_name']) ?></td>
            <td><?= htmlspecialchars($s['campus_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($s['faculty_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($s['department_name'] ?? '-') ?></td>
            <td>
              <?= htmlspecialchars($s['class_name'] ?? '-') ?>
              <?php if(!empty($s['study_mode'])): ?>
                <span style="font-size:11px; background:#eee; padding:2px 6px; border-radius:3px; margin-left:5px;">
                  <?= htmlspecialchars($s['study_mode']) ?>
                </span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($s['parent_name']) ?></td>
            <td>
              <span style="color:<?= $s['status']=='active'?'green':'red' ?>; font-weight:bold;">
                <?= ucfirst($s['status']) ?>
              </span>
            </td>
            <td>
              <button class="btn blue" onclick='editStudent(<?= json_encode($s) ?>)'><i class="fa fa-edit"></i></button>
              <form method="POST" style="display:inline;" onsubmit="return confirmDelete(event, this)">
                <input type="hidden" name="student_id" value="<?= $s['student_id'] ?>">
                <input type="hidden" name="action" value="delete">
                <button class="btn red" type="submit"><i class="fa fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<!-- ================================
     ADD / EDIT STUDENT MODAL FORM
================================ -->
<div class="modal" id="addModal">
  <div class="modal-content">
    <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
    <h2 id="formTitle">Add Student & Parent</h2>

    <form method="POST" enctype="multipart/form-data" onsubmit="return validateForm(this)">
      <input type="hidden" name="action" id="formAction" value="add">
      <input type="hidden" name="student_id" id="student_id">
      <input type="hidden" name="existing_photo" id="existing_photo">
      <input type="hidden" name="study_mode" id="study_mode_hidden" value="Full-Time">
      
      <?php if($is_faculty_admin && $current_user_linked_id): ?>
        <input type="hidden" name="faculty_id" value="<?= $current_user_linked_id ?>">
      <?php endif; ?>

      <div class="grid">
        <!-- Student Information -->
        <div><label>Reg No*</label><input name="reg_no" id="reg_no" required></div>
        <div><label>Full Name*</label><input name="full_name" id="full_name" required></div>
        <div><label>Gender*</label>
          <select name="gender" id="gender" required>
            <option value="">Select</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
          </select>
        </div>
        <div><label>DOB</label><input type="date" name="dob" id="dob"></div>
        <div><label>Email</label><input type="email" name="email" id="email"></div>
        <div><label>Phone</label><input name="phone_number" id="phone_number"></div>
        <div><label>Address</label><input name="address" id="address"></div>
        <div><label>Status</label>
          <select name="status" id="status">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>

        <!-- HIERARCHY SELECTION -->
        <div>
          <label>Campus*</label>
          <select name="campus_id" id="campus" required onchange="onCampusChange(this.value)">
            <option value="">Select Campus</option>
            <?php foreach($campuses as $c): ?>
              <option value="<?= $c['campus_id'] ?>"><?= htmlspecialchars($c['campus_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label>Faculty*</label>
          <select name="faculty_id" id="faculty" required 
            <?php if($is_faculty_admin): ?>disabled<?php endif; ?>
            onchange="onFacultyChange(this.value)" disabled>
            <option value="">Select Faculty</option>
          </select>
        </div>

        <div>
          <label>Department*</label>
          <select name="department_id" id="department" required onchange="onDepartmentChange(this.value)" disabled>
            <option value="">Select Department</option>
          </select>
        </div>

        <div>
          <label>Program*</label>
          <select name="program_id" id="program" required onchange="onProgramChange(this.value)" disabled>
            <option value="">Select Program</option>
          </select>
        </div>

        <div>
          <label>Study Mode</label>
          <select name="study_mode" id="study_mode_select" onchange="onStudyModeChange(this.value)">
            <option value="Full-Time">Full-Time</option>
            <option value="Part-Time">Part-Time</option>
          </select>
        </div>

        <div>
          <label>Study Group*</label>
          <select name="class_id" id="study_group" required disabled>
            <option value="">Select Study Group</option>
          </select>
        </div>

        <div><label>Semester*</label>
          <select name="semester_id" id="semester" required>
            <option value="">Select</option>
            <?php foreach($semesters as $sm): ?>
              <option value="<?= $sm['semester_id'] ?>"><?= htmlspecialchars($sm['semester_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Guardian Section -->
        <div><label>Guardian Name*</label><input name="guardian_name" id="guardian_name" required onkeyup="getParentInfo(this.value)"></div>
        <div><label>Gender*</label>
          <select name="guardian_gender" id="guardian_gender" required>
            <option value="">Select</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
          </select>
        </div>
        <div><label>Phone</label><input name="guardian_phone" id="guardian_phone"></div>
        <div><label>Email</label><input type="email" name="guardian_email" id="guardian_email"></div>
        <div><label>Address</label><input name="guardian_address" id="guardian_address"></div>
        <div><label>Occupation</label><input name="guardian_occupation" id="guardian_occupation"></div>
        <div><label>Status</label>
          <select name="guardian_status" id="guardian_status">
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        <div><label>Relation*</label>
          <select name="guardian_relation" id="guardian_relation" required>
            <option value="">Select</option>
            <option value="father">Father</option>
            <option value="mother">Mother</option>
            <option value="sister">Sister</option>
            <option value="brother">Brother</option>
            <option value="guardian">Guardian</option>
            <option value="other">Other</option>
          </select>
        </div>

        <div><label>Photo</label><input type="file" name="photo" id="photo" accept="image/*"></div>
      </div>

      <div style="grid-column: span 4; margin-top: 20px;">
        <button class="btn green save-btn">Save Record</button>
      </div>
    </form>
  </div>
</div>

<style>
body {
  font-family:'Poppins',sans-serif;
  background:#f7f9fb;
  margin:0;
}
.main-content {
  padding:25px;
  margin-left:250px;
  margin-top:90px;
  transition:margin-left 0.3s ease;
}

.sidebar.collapsed ~ .main-content {
  margin-left: 70px;
}

.stats-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 15px;
  margin-bottom: 20px;
}

.card {
  background: #fff;
  padding: 20px;
  border-radius: 10px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.08);
  text-align: center;
}

.card h3 {
  font-size: 28px;
  margin: 0 0 10px 0;
  color: #0072CE;
}

.card p {
  margin: 0;
  color: #666;
  font-size: 14px;
}

.top-bar {
  display:flex;
  justify-content:space-between;
  align-items:center;
  background:#fff;
  padding:15px 20px;
  border-radius:10px;
  box-shadow:0 2px 6px rgba(0,0,0,0.08);
  margin-bottom:15px;
}
.top-bar h2 { color:#0072CE; margin:0; }
.btn {
  border:none;
  padding:8px 14px;
  border-radius:6px;
  font-weight:600;
  cursor:pointer;
  margin:2px;
  font-size:13px;
}
.btn.blue{background:#0072CE;color:#fff;}
.btn.green{background:#00843D;color:#fff;}
.btn.red{background:#C62828;color:#fff;}
.btn.gray{background:#6c757d;color:#fff;}
.alert {
  position:fixed;
  top:50%;
  left:50%;
  transform:translate(-50%,-50%);
  padding:20px 25px;
  border-radius:10px;
  color:#fff;
  font-weight:600;
  z-index:9999;
  animation:fadeIn .4s ease;
}
.alert.hide{opacity:0;transition:opacity .5s ease;}
.alert-success{background:#00843D;}
.alert-error{background:#C62828;}
@keyframes fadeIn {
  from{opacity:0;transform:translate(-50%,-60%);}
  to{opacity:1;transform:translate(-50%,-50%);}
}

.filter-box {
  background:#fff;
  padding:20px;
  border-radius:10px;
  box-shadow:0 2px 8px rgba(0,0,0,0.08);
  margin-bottom:15px;
}

.grid {
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));
  gap:15px;
}

.table-responsive {
  width: 100%;
  overflow-x: auto;
  overflow-y: auto;
  max-height: 450px;
  background: #fff;
  border-radius: 8px;
  box-shadow:0 2px 8px rgba(0,0,0,0.08);
  margin-top: 10px;
}

thead th {
  position: sticky;
  top: 0;
  background: #0072CE;
  color: #fff;
  z-index: 2;
}

table {
  width:100%;
  border-collapse:collapse;
  background:#fff;
}
th,td {
  padding:10px 12px;
  text-align:left;
  border-bottom:1px solid #eee;
  font-size:14px;
}
thead th { background:#0072CE; color:#fff; }
.modal {
  display:none;
  position:fixed;
  top:0; left:0;
  width:100%; height:100%;
  background:rgba(0,0,0,0.4);
  justify-content:center;
  align-items:center;
  z-index:9999;
}
.modal.show{display:flex;}
.modal-content {
  background:#fff;
  width:95%;
  max-width:1100px;
  border-radius:12px;
  padding:25px;
  position:relative;
  overflow-y:auto;
  max-height:90vh;
}
.close-modal {
  position:absolute;
  top:10px; right:15px;
  font-size:22px;
  cursor:pointer;
}
form label {
  font-weight:600;
  color:#0072CE;
  font-size:13px;
}
form input, form select {
  width:100%;
  padding:8px;
  border:1px solid #ccc;
  border-radius:6px;
  font-size:13px;
}
.save-btn {
  grid-column:span 4;
  width:100%;
  padding:10px;
  font-size:14px;
}
</style>

<script>
// ===========================================
// GLOBAL VARIABLES
// ===========================================
const isFacultyAdmin = <?= $is_faculty_admin ? 'true' : 'false' ?>;
const facultyAdminId = <?= $current_user_linked_id ?: 'null' ?>;

// ===========================================
// FILTER FUNCTIONS - LIKE ATTENDANCE SYSTEM
// ===========================================

// Filter hierarchy functions
function onFilterCampusChange(campusId) {
  const facultySelect = document.getElementById('filter_faculty');
  const departmentSelect = document.getElementById('filter_department');
  const programSelect = document.getElementById('filter_program');
  const studyGroupSelect = document.getElementById('filter_study_group');
  
  if (!campusId) {
    if (!isFacultyAdmin) {
      facultySelect.innerHTML = '<option value="">All Faculties</option>';
      facultySelect.disabled = true;
    }
    departmentSelect.innerHTML = '<option value="">All Departments</option>';
    departmentSelect.disabled = true;
    programSelect.innerHTML = '<option value="">All Programs</option>';
    programSelect.disabled = true;
    studyGroupSelect.innerHTML = '<option value="">All Study Groups</option>';
    studyGroupSelect.disabled = true;
    return;
  }
  
  if (!isFacultyAdmin) {
    facultySelect.innerHTML = '<option value="">Loading...</option>';
    facultySelect.disabled = true;
  }
  
  // For faculty admin, we don't need to load faculties
  if (isFacultyAdmin) {
    // Directly load departments for this faculty admin
    onFilterFacultyChange(facultyAdminId);
    return;
  }
  
  fetch(`?ajax=get_faculties_by_campus&campus_id=${campusId}`)
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
        facultySelect.disabled = false;
      } else {
        facultySelect.innerHTML = '<option value="">No faculties</option>';
        facultySelect.disabled = true;
      }
      
      // Reset lower dropdowns
      departmentSelect.innerHTML = '<option value="">All Departments</option>';
      departmentSelect.disabled = true;
      programSelect.innerHTML = '<option value="">All Programs</option>';
      programSelect.disabled = true;
      studyGroupSelect.innerHTML = '<option value="">All Study Groups</option>';
      studyGroupSelect.disabled = true;
    });
}

function onFilterFacultyChange(facultyId) {
  const campusId = document.getElementById('filter_campus').value;
  const departmentSelect = document.getElementById('filter_department');
  const programSelect = document.getElementById('filter_program');
  const studyGroupSelect = document.getElementById('filter_study_group');
  
  if (!facultyId || !campusId) {
    departmentSelect.innerHTML = '<option value="">All Departments</option>';
    departmentSelect.disabled = true;
    programSelect.innerHTML = '<option value="">All Programs</option>';
    programSelect.disabled = true;
    studyGroupSelect.innerHTML = '<option value="">All Study Groups</option>';
    studyGroupSelect.disabled = true;
    return;
  }
  
  departmentSelect.innerHTML = '<option value="">Loading...</option>';
  departmentSelect.disabled = true;
  
  fetch(`?ajax=get_departments_by_faculty&faculty_id=${facultyId}&campus_id=${campusId}`)
    .then(response => response.json())
    .then(data => {
      departmentSelect.innerHTML = '<option value="">All Departments</option>';
      
      if (data.status === 'success' && data.departments.length > 0) {
        data.departments.forEach(dept => {
          const option = document.createElement('option');
          option.value = dept.department_id;
          option.textContent = dept.department_name;
          departmentSelect.appendChild(option);
        });
        departmentSelect.disabled = false;
      } else {
        departmentSelect.innerHTML = '<option value="">No departments</option>';
        departmentSelect.disabled = true;
      }
      
      // Reset lower dropdowns
      programSelect.innerHTML = '<option value="">All Programs</option>';
      programSelect.disabled = true;
      studyGroupSelect.innerHTML = '<option value="">All Study Groups</option>';
      studyGroupSelect.disabled = true;
    });
}

function onFilterDepartmentChange(departmentId) {
  const campusId = document.getElementById('filter_campus').value;
  const facultyId = document.getElementById('filter_faculty').value;
  const programSelect = document.getElementById('filter_program');
  const studyGroupSelect = document.getElementById('filter_study_group');
  
  if (!departmentId || !facultyId || !campusId) {
    programSelect.innerHTML = '<option value="">All Programs</option>';
    programSelect.disabled = true;
    studyGroupSelect.innerHTML = '<option value="">All Study Groups</option>';
    studyGroupSelect.disabled = true;
    return;
  }
  
  programSelect.innerHTML = '<option value="">Loading...</option>';
  programSelect.disabled = true;
  
  fetch(`?ajax=get_programs_by_department&department_id=${departmentId}&faculty_id=${facultyId}&campus_id=${campusId}`)
    .then(response => response.json())
    .then(data => {
      programSelect.innerHTML = '<option value="">All Programs</option>';
      
      if (data.status === 'success' && data.programs.length > 0) {
        data.programs.forEach(program => {
          const option = document.createElement('option');
          option.value = program.program_id;
          option.textContent = program.program_name;
          programSelect.appendChild(option);
        });
        programSelect.disabled = false;
      } else {
        programSelect.innerHTML = '<option value="">No programs</option>';
        programSelect.disabled = true;
      }
      
      // Reset lower dropdown
      studyGroupSelect.innerHTML = '<option value="">All Study Groups</option>';
      studyGroupSelect.disabled = true;
    });
}

function onFilterProgramChange(programId) {
  const campusId = document.getElementById('filter_campus').value;
  const facultyId = document.getElementById('filter_faculty').value;
  const departmentId = document.getElementById('filter_department').value;
  const studyGroupSelect = document.getElementById('filter_study_group');
  
  if (!programId || !departmentId || !facultyId || !campusId) {
    studyGroupSelect.innerHTML = '<option value="">All Study Groups</option>';
    studyGroupSelect.disabled = true;
    return;
  }
  
  studyGroupSelect.innerHTML = '<option value="">Loading...</option>';
  studyGroupSelect.disabled = true;
  
  fetch(`?ajax=get_study_groups_by_program&program_id=${programId}&department_id=${departmentId}&faculty_id=${facultyId}&campus_id=${campusId}`)
    .then(response => response.json())
    .then(data => {
      studyGroupSelect.innerHTML = '<option value="">All Study Groups</option>';
      
      if (data.status === 'success' && data.study_groups.length > 0) {
        data.study_groups.forEach(sg => {
          const option = document.createElement('option');
          option.value = sg.class_id;
          option.textContent = `${sg.class_name} (${sg.study_mode ? sg.study_mode : 'N/A'})`;
          studyGroupSelect.appendChild(option);
        });
        studyGroupSelect.disabled = false;
      } else {
        studyGroupSelect.innerHTML = '<option value="">No study groups</option>';
        studyGroupSelect.disabled = true;
      }
    });
}

function resetFilter() {
  window.location.href = 'students.php';
}

// ===========================================
// MODAL FUNCTIONS
// ===========================================

function openModal(id){
  const m = document.getElementById(id);
  m.classList.add('show');
  document.getElementById('formAction').value = 'add';
  document.getElementById('formTitle').innerText = 'Add Student';
  m.querySelector('form').reset();
  document.getElementById('student_id').value = '';
  
  resetAllHierarchy();
}

function closeModal(id){
  document.getElementById(id).classList.remove('show');
}

function validateForm(f){
  const requiredFields = f.querySelectorAll('[required]');
  for(let i of requiredFields){
    if(!i.value.trim()){
      alert('⚠️ Please fill all required fields!');
      i.focus();
      return false;
    }
  }
  return true;
}

function confirmDelete(event, form) {
  event.preventDefault();
  if (confirm('Delete this student?')) {
    form.submit();
  }
  return false;
}

function getParentInfo(n) {
  if (n.length < 3) return;

  fetch('?ajax=get_parent&name=' + encodeURIComponent(n))
    .then(r => r.json())
    .then(p => {
      if (p.parent_id) {
        document.getElementById('guardian_gender').value = p.gender || '';
        document.getElementById('guardian_phone').value = p.phone || '';
        document.getElementById('guardian_email').value = p.email || '';
        document.getElementById('guardian_address').value = p.address || '';
        document.getElementById('guardian_occupation').value = p.occupation || '';
        document.getElementById('guardian_status').value = p.status || 'active';
        
        document.getElementById('guardian_name').style.border = '2px solid green';
        document.getElementById('guardian_name').title = 'Existing parent found';
      } else {
        document.getElementById('guardian_name').style.border = '1px solid #ccc';
        document.getElementById('guardian_name').title = '';
      }
    });
}

function resetAllHierarchy() {
  document.getElementById('campus').value = '';
  document.getElementById('campus').disabled = false;
  
  resetDependentDropdowns(['faculty', 'department', 'program', 'study_group']);
}

function resetDependentDropdowns(fields) {
  fields.forEach(field => {
    const select = document.getElementById(field);
    if (select) {
      select.innerHTML = '<option value="">Select ' + field.charAt(0).toUpperCase() + field.slice(1).replace('_', ' ') + '</option>';
      select.disabled = true;
      select.value = '';
    }
  });
}

// Modal hierarchy functions
function onCampusChange(campusId) {
  if (!campusId) {
    resetDependentDropdowns(['faculty', 'department', 'program', 'study_group']);
    return;
  }
  
  const lowerIds = ['faculty', 'department', 'program', 'study_group'];
  lowerIds.forEach(id => {
    const select = document.getElementById(id);
    select.innerHTML = '<option value="">Loading...</option>';
    select.disabled = true;
  });
  
  if (isFacultyAdmin && facultyAdminId) {
    // For faculty admin, directly set faculty and load departments
    const facultySelect = document.getElementById('faculty');
    facultySelect.innerHTML = '<option value="' + facultyAdminId + '">Loading faculty...</option>';
    facultySelect.value = facultyAdminId;
    facultySelect.disabled = true;
    
    // Load departments for this faculty
    loadDepartments(facultyAdminId, campusId);
  } else {
    // For super admin, load faculties first
    loadFaculties(campusId);
  }
}

function onFacultyChange(facultyId) {
  const campusId = document.getElementById('campus').value;
  if (!facultyId || !campusId) {
    resetDependentDropdowns(['department', 'program', 'study_group']);
    return;
  }
  
  document.getElementById('department').innerHTML = '<option value="">Loading...</option>';
  document.getElementById('department').disabled = true;
  document.getElementById('program').innerHTML = '<option value="">Select Program</option>';
  document.getElementById('program').disabled = true;
  document.getElementById('program').value = '';
  document.getElementById('study_group').innerHTML = '<option value="">Select Study Group</option>';
  document.getElementById('study_group').disabled = true;
  document.getElementById('study_group').value = '';
  
  loadDepartments(facultyId, campusId);
}

function onDepartmentChange(departmentId) {
  const facultyId = document.getElementById('faculty').value;
  const campusId = document.getElementById('campus').value;
  
  if (!departmentId || !facultyId || !campusId) {
    resetDependentDropdowns(['program', 'study_group']);
    return;
  }
  
  document.getElementById('program').innerHTML = '<option value="">Loading...</option>';
  document.getElementById('program').disabled = true;
  document.getElementById('study_group').innerHTML = '<option value="">Select Study Group</option>';
  document.getElementById('study_group').disabled = true;
  document.getElementById('study_group').value = '';
  
  loadPrograms(departmentId, facultyId, campusId);
}

function onProgramChange(programId) {
  const departmentId = document.getElementById('department').value;
  const facultyId = document.getElementById('faculty').value;
  const campusId = document.getElementById('campus').value;
  const studyMode = document.getElementById('study_mode_select').value;
  
  if (!programId || !departmentId || !facultyId || !campusId) {
    document.getElementById('study_group').innerHTML = '<option value="">Select Study Group</option>';
    document.getElementById('study_group').disabled = true;
    return;
  }
  
  document.getElementById('study_group').innerHTML = '<option value="">Loading...</option>';
  document.getElementById('study_group').disabled = true;
  
  loadStudyGroups(programId, departmentId, facultyId, campusId, studyMode);
}

function onStudyModeChange(studyMode) {
  const programId = document.getElementById('program').value;
  const departmentId = document.getElementById('department').value;
  const facultyId = document.getElementById('faculty').value;
  const campusId = document.getElementById('campus').value;
  
  document.getElementById('study_mode_hidden').value = studyMode;
  
  if (programId && departmentId && facultyId && campusId) {
    loadStudyGroups(programId, departmentId, facultyId, campusId, studyMode);
  }
}

// Data loading functions for modal
async function loadFaculties(campusId) {
  try {
    const response = await fetch(`?ajax=get_faculties_by_campus&campus_id=${campusId}`);
    const data = await response.json();
    
    const facultySelect = document.getElementById('faculty');
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
      facultySelect.innerHTML = '<option value="">No faculties found</option>';
      facultySelect.disabled = true;
    }
  } catch (error) {
    console.error('Error loading faculties:', error);
    document.getElementById('faculty').innerHTML = '<option value="">Error loading</option>';
  }
}

async function loadDepartments(facultyId, campusId) {
  try {
    const response = await fetch(`?ajax=get_departments_by_faculty&faculty_id=${facultyId}&campus_id=${campusId}`);
    const data = await response.json();
    
    const deptSelect = document.getElementById('department');
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
      deptSelect.innerHTML = '<option value="">No departments found</option>';
      deptSelect.disabled = true;
    }
  } catch (error) {
    console.error('Error loading departments:', error);
    document.getElementById('department').innerHTML = '<option value="">Error loading</option>';
  }
}

async function loadPrograms(departmentId, facultyId, campusId) {
  try {
    const response = await fetch(`?ajax=get_programs_by_department&department_id=${departmentId}&faculty_id=${facultyId}&campus_id=${campusId}`);
    const data = await response.json();
    
    const programSelect = document.getElementById('program');
    programSelect.innerHTML = '<option value="">Select Program</option>';
    
    if (data.status === 'success' && data.programs.length > 0) {
      data.programs.forEach(program => {
        const option = document.createElement('option');
        option.value = program.program_id;
        option.textContent = program.program_name;
        programSelect.appendChild(option);
      });
      programSelect.disabled = false;
    } else {
      programSelect.innerHTML = '<option value="">No programs found</option>';
      programSelect.disabled = true;
    }
  } catch (error) {
    console.error('Error loading programs:', error);
    document.getElementById('program').innerHTML = '<option value="">Error loading</option>';
  }
}

async function loadStudyGroups(programId, departmentId, facultyId, campusId, studyMode = '') {
  try {
    const response = await fetch(`?ajax=get_study_groups_by_program&program_id=${programId}&department_id=${departmentId}&faculty_id=${facultyId}&campus_id=${campusId}`);
    const data = await response.json();
    
    const studyGroupSelect = document.getElementById('study_group');
    studyGroupSelect.innerHTML = '<option value="">Select Study Group</option>';
    
    if (data.status === 'success' && data.study_groups.length > 0) {
      let studyGroups = data.study_groups;
      
      // Filter by study mode if specified
      if (studyMode) {
        studyGroups = studyGroups.filter(sg => sg.study_mode === studyMode);
      }
      
      if (studyGroups.length > 0) {
        studyGroups.forEach(sg => {
          const option = document.createElement('option');
          option.value = sg.class_id;
          option.textContent = `${sg.class_name} (${sg.study_mode ? sg.study_mode : 'N/A'})`;
          studyGroupSelect.appendChild(option);
        });
        studyGroupSelect.disabled = false;
      } else {
        studyGroupSelect.innerHTML = `<option value="">No ${studyMode} study groups found</option>`;
        studyGroupSelect.disabled = true;
      }
    } else {
      studyGroupSelect.innerHTML = '<option value="">No study groups found</option>';
      studyGroupSelect.disabled = true;
    }
  } catch (error) {
    console.error('Error loading study groups:', error);
    document.getElementById('study_group').innerHTML = '<option value="">Error loading</option>';
  }
}

// Edit student function
async function editStudent(studentData){
  openModal('addModal');
  document.getElementById('formTitle').innerText = 'Edit Student';
  document.getElementById('formAction').value = 'update';
  
  // Set basic student info
  document.getElementById('student_id').value = studentData.student_id;
  document.getElementById('existing_photo').value = studentData.photo_path || '';
  document.getElementById('reg_no').value = studentData.reg_no || '';
  document.getElementById('full_name').value = studentData.full_name || '';
  document.getElementById('gender').value = studentData.gender || '';
  document.getElementById('dob').value = studentData.dob || '';
  document.getElementById('email').value = studentData.email || '';
  document.getElementById('phone_number').value = studentData.phone_number || '';
  document.getElementById('address').value = studentData.address || '';
  document.getElementById('status').value = studentData.status || 'active';

  // Set guardian info
  document.getElementById('guardian_name').value = studentData.parent_name || '';
  document.getElementById('guardian_gender').value = studentData.guardian_gender || '';
  document.getElementById('guardian_phone').value = studentData.parent_phone || '';
  document.getElementById('guardian_email').value = studentData.guardian_email || '';
  document.getElementById('guardian_address').value = studentData.guardian_address || '';
  document.getElementById('guardian_occupation').value = studentData.guardian_occupation || '';
  document.getElementById('guardian_status').value = studentData.guardian_status || 'active';
  document.getElementById('guardian_relation').value = studentData.relation_type || 'guardian';

  // Set semester
  if (studentData.semester_id) {
    document.getElementById('semester').value = studentData.semester_id;
  }

  // Set study mode
  if (studentData.study_mode) {
    document.getElementById('study_mode_select').value = studentData.study_mode;
    document.getElementById('study_mode_hidden').value = studentData.study_mode;
  }

  // Reset hierarchy first
  resetAllHierarchy();
  
  // If student has campus, load its hierarchy
  if (studentData.campus_id) {
    document.getElementById('campus').value = studentData.campus_id;
    
    if (isFacultyAdmin) {
      // For faculty admin, set faculty directly
      document.getElementById('faculty').innerHTML = '<option value="' + facultyAdminId + '">Loading...</option>';
      document.getElementById('faculty').value = facultyAdminId;
      document.getElementById('faculty').disabled = true;
      
      if (studentData.department_id) {
        setTimeout(() => {
          loadDepartments(facultyAdminId, studentData.campus_id).then(() => {
            document.getElementById('department').value = studentData.department_id;
            
            if (studentData.program_id) {
              setTimeout(() => {
                loadPrograms(studentData.department_id, facultyAdminId, studentData.campus_id).then(() => {
                  document.getElementById('program').value = studentData.program_id;
                  
                  if (studentData.class_id) {
                    setTimeout(() => {
                      loadStudyGroups(studentData.program_id, studentData.department_id, facultyAdminId, studentData.campus_id, studentData.study_mode).then(() => {
                        document.getElementById('study_group').value = studentData.class_id;
                      });
                    }, 300);
                  }
                });
              }, 300);
            }
          });
        }, 300);
      }
    } else {
      // For super admin, load faculties first
      await loadFaculties(studentData.campus_id);
      
      if (studentData.faculty_id) {
        setTimeout(() => {
          document.getElementById('faculty').value = studentData.faculty_id;
          
          if (studentData.department_id) {
            setTimeout(() => {
              loadDepartments(studentData.faculty_id, studentData.campus_id).then(() => {
                document.getElementById('department').value = studentData.department_id;
                
                if (studentData.program_id) {
                  setTimeout(() => {
                    loadPrograms(studentData.department_id, studentData.faculty_id, studentData.campus_id).then(() => {
                      document.getElementById('program').value = studentData.program_id;
                      
                      if (studentData.class_id) {
                        setTimeout(() => {
                          loadStudyGroups(studentData.program_id, studentData.department_id, studentData.faculty_id, studentData.campus_id, studentData.study_mode).then(() => {
                            document.getElementById('study_group').value = studentData.class_id;
                          });
                        }, 300);
                      }
                    });
                  }, 300);
                }
              });
            }, 300);
          }
        }, 300);
      }
    }
  }
}

// Auto-update study groups when study mode changes
document.getElementById('study_mode_select')?.addEventListener('change', function() {
  const programId = document.getElementById('program').value;
  const departmentId = document.getElementById('department').value;
  const facultyId = document.getElementById('faculty').value;
  const campusId = document.getElementById('campus').value;
  const studyMode = this.value;
  
  document.getElementById('study_mode_hidden').value = studyMode;
  
  if (programId && departmentId && facultyId && campusId) {
    loadStudyGroups(programId, departmentId, facultyId, campusId, studyMode);
  }
});
</script>

<?php include('../includes/footer.php'); ?>
<?php ob_end_flush(); ?>