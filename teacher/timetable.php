<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// Hubi in user-ka uu galay (logged in)
if (empty($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

// Hubi in user-ka uu yahay teacher (macalin)
$user = $_SESSION['user'];
$role = strtolower(str_replace(' ', '_', $user['role'] ?? ''));

// Ogolow kaliya teacher-ka
if ($role !== 'teacher') {
    header("Location: ../dashboard.php");
    exit;
}

// Macluumaadka user-ka
$name = htmlspecialchars($user['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
$email = htmlspecialchars($user['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$phone = htmlspecialchars($user['phone_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$photo = !empty($user['profile_photo_path'])
    ? "../" . htmlspecialchars($user['profile_photo_path'], ENT_QUOTES, 'UTF-8')
    : "../upload/profiles/default.png";

// Hel teacher_id from linked_id
$teacher_id = $user['linked_id'] ?? null;

// Hubi in teacher_id uu jiro
if (!$teacher_id) {
    // Hadduusan jirin, isku day inaad ka hesho table-ka teachers
    $stmt = $pdo->prepare("SELECT teacher_id FROM teachers WHERE email = ? OR teacher_name LIKE ?");
    $searchName = '%' . $user['username'] . '%';
    $stmt->execute([$user['email'], $searchName]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($teacher) {
        $teacher_id = $teacher['teacher_id'];
    } else {
        // Ugu dambayn, isku day inaad ka hesho table-ka users
        $stmt = $pdo->prepare("SELECT linked_id FROM users WHERE user_id = ?");
        $stmt->execute([$user['user_id']]);
        $linked = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($linked && $linked['linked_id']) {
            $teacher_id = $linked['linked_id'];
        } else {
            // Haddii aan la helin, isticmaal teacher_id = 1 si aad u aragto waxa jira
            $teacher_id = 1;
        }
    }
}

// Filter parameters
$search = $_GET['search'] ?? '';
$academic_term_filter = $_GET['academic_term'] ?? '';
$academic_year_filter = $_GET['academic_year'] ?? '';
$day_filter = $_GET['day'] ?? '';
$status_filter = $_GET['status'] ?? '';
$campus_filter = $_GET['campus'] ?? '';
$class_filter = $_GET['class'] ?? '';

// Hel academic years-ka firfircoon
$current_date = date('Y-m-d');
$academic_years = $pdo->prepare("
    SELECT academic_year_id, year_name, start_date, end_date, status 
    FROM academic_year 
    WHERE status = 'active'
    ORDER BY start_date DESC
");
$academic_years->execute();
$academic_years = $academic_years->fetchAll(PDO::FETCH_ASSOC);

// Hel academic terms-ka firfircoon
$academic_terms = $pdo->prepare("
    SELECT at.*, ay.year_name
    FROM academic_term at
    JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
    WHERE at.status = 'active'
    AND ay.status = 'active'
    ORDER BY at.start_date DESC
");
$academic_terms->execute();
$academic_terms = $academic_terms->fetchAll(PDO::FETCH_ASSOC);

// Hel teacher info
$teacher_info = $pdo->prepare("SELECT * FROM teachers WHERE teacher_id = ?");
$teacher_info->execute([$teacher_id]);
$teacher = $teacher_info->fetch(PDO::FETCH_ASSOC);

// Maalinta tusmada (array for day filtering)
$days_of_week = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$days_full = [
    'Sun' => 'Sunday',
    'Mon' => 'Monday', 
    'Tue' => 'Tuesday', 
    'Wed' => 'Wednesday', 
    'Thu' => 'Thursday', 
    'Fri' => 'Friday', 
    'Sat' => 'Saturday'
];

// ===========================================
// HEL MACLUUMAADKA SCHEDULE-KA TEACHER-KA KA TIMETABLE
// ===========================================

$query = "
    SELECT 
        tt.timetable_id,
        tt.day_of_week,
        TIME_FORMAT(tt.start_time, '%h:%i %p') as start_time_formatted,
        TIME_FORMAT(tt.end_time, '%h:%i %p') as end_time_formatted,
        tt.start_time,
        tt.end_time,
        tt.status as tt_status,
        tt.created_at,
        
        s.subject_id,
        s.subject_name,
        s.subject_code,
        s.credit_hours,
        s.status as subject_status,
        
        c.class_id,
        c.class_name,
        c.study_mode,
        
        p.program_id,
        p.program_name,
        p.program_code,
        
        d.department_id,
        d.department_name,
        
        f.faculty_id,
        f.faculty_name,
        
        cam.campus_id,
        cam.campus_name,
        
        r.room_id,
        r.room_name,
        r.room_code,
        r.building_name,
        
        at.academic_term_id,
        at.term_name,
        DATE_FORMAT(at.start_date, '%b %d, %Y') as term_start_formatted,
        DATE_FORMAT(at.end_date, '%b %d, %Y') as term_end_formatted,
        at.start_date as term_start,
        at.end_date as term_end,
        
        ay.academic_year_id,
        ay.year_name,
        
        sem.semester_id,
        sem.semester_name
        
    FROM timetable tt
    LEFT JOIN subject s ON tt.subject_id = s.subject_id
    LEFT JOIN classes c ON tt.class_id = c.class_id
    LEFT JOIN programs p ON tt.program_id = p.program_id
    LEFT JOIN departments d ON tt.department_id = d.department_id
    LEFT JOIN faculties f ON tt.faculty_id = f.faculty_id
    LEFT JOIN campus cam ON tt.campus_id = cam.campus_id
    LEFT JOIN rooms r ON tt.room_id = r.room_id
    LEFT JOIN academic_term at ON tt.academic_term_id = at.academic_term_id
    LEFT JOIN academic_year ay ON at.academic_year_id = ay.academic_year_id
    LEFT JOIN semester sem ON s.semester_id = sem.semester_id
    WHERE tt.teacher_id = ?
";

$params = [$teacher_id];

// Search filter
if (!empty($search)) {
    $query .= " AND (s.subject_name LIKE ? OR s.subject_code LIKE ? OR c.class_name LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

// Academic Term filter
if (!empty($academic_term_filter)) {
    $query .= " AND tt.academic_term_id = ?";
    $params[] = $academic_term_filter;
}

// Academic Year filter
if (!empty($academic_year_filter)) {
    $query .= " AND ay.academic_year_id = ?";
    $params[] = $academic_year_filter;
}

// Day of week filter
if (!empty($day_filter)) {
    $query .= " AND tt.day_of_week = ?";
    $params[] = $day_filter;
}

// Status filter (timetable status)
if (!empty($status_filter)) {
    $query .= " AND tt.status = ?";
    $params[] = $status_filter;
}

// Campus filter
if (!empty($campus_filter)) {
    $query .= " AND cam.campus_id = ?";
    $params[] = $campus_filter;
}

// Class filter
if (!empty($class_filter)) {
    $query .= " AND c.class_id = ?";
    $params[] = $class_filter;
}

// Ku dar order by si loo kala saaro maalinta iyo waqtiga
$query .= " ORDER BY 
    FIELD(tt.day_of_week, 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'),
    tt.start_time ASC
";

// Execute query
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$timetable_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by day for weekly view
$weekly_schedule = [];
foreach ($timetable_entries as $entry) {
    $day = $entry['day_of_week'];
    if (!isset($weekly_schedule[$day])) {
        $weekly_schedule[$day] = [];
    }
    $weekly_schedule[$day][] = $entry;
}

// Get unique campuses for filter
$campuses = $pdo->prepare("
    SELECT DISTINCT cam.campus_id, cam.campus_name
    FROM campus cam
    JOIN timetable tt ON cam.campus_id = tt.campus_id
    WHERE tt.teacher_id = ?
    ORDER BY cam.campus_name
");
$campuses->execute([$teacher_id]);
$campuses = $campuses->fetchAll(PDO::FETCH_ASSOC);

// Get unique classes for filter
$classes = $pdo->prepare("
    SELECT DISTINCT c.class_id, c.class_name
    FROM classes c
    JOIN timetable tt ON c.class_id = tt.class_id
    WHERE tt.teacher_id = ?
    ORDER BY c.class_name
");
$classes->execute([$teacher_id]);
$classes = $classes->fetchAll(PDO::FETCH_ASSOC);

// Xisaabi wadarta guud
$total_sessions = count($timetable_entries);
$total_subjects = count(array_unique(array_column($timetable_entries, 'subject_id')));
$total_classes = count(array_unique(array_column($timetable_entries, 'class_id')));

// Maanta
$today = date('D');
$today_sessions = count($weekly_schedule[$today] ?? []);

$role_display = "Teacher (Macalin)";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Schedule | Teacher Dashboard | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ===========================================
   CSS VARIABLES & RESET - from courses.php
=========================================== */
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
  --border-color: #E0E0E0;
  --shadow-color: rgba(0, 0, 0, 0.08);
  --transition: all 0.3s ease;
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: 'Segoe UI', 'Poppins', sans-serif;
}

body {
  background: var(--light-gray);
  color: var(--dark-color);
  min-height: 100vh;
  overflow-x: hidden;
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
   PAGE HEADER - from courses.php
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

.role-badge {
  background: var(--secondary-color);
  color: white;
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
  margin-left: 10px;
}

/* ==============================
   INFO PANEL - from courses.php
============================== */
.info-panel {
  background: linear-gradient(135deg, #f5f7fa 0%, #e9edf5 100%);
  border-radius: 8px;
  padding: 15px 20px;
  margin-bottom: 20px;
  border-left: 4px solid var(--secondary-color);
  display: flex;
  align-items: center;
  gap: 15px;
  flex-wrap: wrap;
}

.info-panel i {
  font-size: 24px;
  color: var(--secondary-color);
}

.info-panel p {
  color: var(--dark-color);
  font-size: 14px;
  line-height: 1.5;
  margin: 0;
}

.info-panel strong {
  color: var(--primary-color);
}

.info-stats {
  display: flex;
  gap: 20px;
  margin-left: auto;
}

.stat-item {
  text-align: center;
}

.stat-value {
  font-size: 24px;
  font-weight: 700;
  color: var(--primary-color);
  line-height: 1.2;
}

.stat-label {
  font-size: 12px;
  color: #666;
}

/* ==============================
   FILTERS SECTION - from courses.php
============================== */
.filters-container {
  background: var(--white);
  border-radius: 10px;
  padding: 20px;
  margin-bottom: 20px;
  box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
}

.filter-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 15px;
}

.filter-header h3 {
  color: var(--secondary-color);
  font-size: 16px;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}

.filter-form {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 15px;
  align-items: flex-end;
}

.filter-group {
  position: relative;
}

.filter-group label {
  display: block;
  margin-bottom: 6px;
  font-weight: 500;
  font-size: 12px;
  color: #555;
}

.filter-input {
  width: 100%;
  padding: 10px 12px;
  border: 1.5px solid #ddd;
  border-radius: 6px;
  font-size: 13px;
  transition: all 0.2s;
  background: #f9f9f9;
}

.filter-input:focus {
  outline: none;
  border-color: var(--secondary-color);
  background: #fff;
  box-shadow: 0 0 0 3px rgba(0,114,206,0.1);
}

.filter-actions {
  display: flex;
  gap: 10px;
  align-items: flex-end;
}

.filter-btn {
  padding: 10px 16px;
  border: none;
  border-radius: 6px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
  display: flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
  font-size: 13px;
}

.apply-btn {
  background: var(--primary-color);
  color: #fff;
}

.apply-btn:hover {
  background: var(--light-color);
}

.clear-btn {
  background: #6c757d;
  color: #fff;
}

.clear-btn:hover {
  background: #5a6268;
}

/* ==============================
   STATS CARDS - from courses.php
============================== */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 20px;
  margin-bottom: 25px;
}

.stat-card {
  background: var(--white);
  border-radius: 10px;
  padding: 20px;
  box-shadow: 0 3px 10px rgba(0,0,0,0.05);
  display: flex;
  align-items: center;
  gap: 15px;
  border-left: 4px solid var(--primary-color);
  transition: var(--transition);
}

.stat-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 5px 20px rgba(0,0,0,0.1);
}

.stat-icon {
  width: 50px;
  height: 50px;
  border-radius: 10px;
  background: rgba(0, 132, 61, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--primary-color);
  font-size: 24px;
}

.stat-info h3 {
  font-size: 28px;
  font-weight: 700;
  color: var(--dark-color);
  line-height: 1.2;
  margin-bottom: 5px;
}

.stat-info p {
  color: #666;
  font-size: 14px;
  margin: 0;
}

/* ==============================
   SCHEDULE GRID - custom for timetable
============================== */
.schedule-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 15px;
  margin-top: 20px;
}

.day-column {
  background: var(--white);
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 3px 10px rgba(0,0,0,0.05);
  border: 1px solid var(--border-color);
  transition: var(--transition);
}

.day-column:hover {
  transform: translateY(-3px);
  box-shadow: 0 8px 20px rgba(0,0,0,0.1);
}

.day-header {
  background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
  color: white;
  padding: 15px;
  text-align: center;
  position: relative;
}

.day-header.today {
  background: linear-gradient(135deg, var(--warning-color), #ff9800);
}

.day-header h3 {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 5px;
}

.day-header p {
  font-size: 12px;
  opacity: 0.9;
}

.today-badge {
  position: absolute;
  top: 5px;
  right: 5px;
  background: rgba(255,255,255,0.3);
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 10px;
  font-weight: 600;
}

.day-content {
  padding: 15px;
  min-height: 200px;
}

.session-card {
  background: #f8f9fa;
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 10px;
  border-left: 4px solid;
  transition: var(--transition);
  cursor: pointer;
}

.session-card:hover {
  transform: translateX(3px);
  box-shadow: 0 3px 8px rgba(0,0,0,0.1);
}

.session-card.active {
  border-left-color: var(--primary-color);
  background: linear-gradient(135deg, #f0fff4, #f8f9fa);
}

.session-card.inactive {
  border-left-color: var(--danger-color);
  opacity: 0.7;
}

.session-card.cancelled {
  border-left-color: #9e9e9e;
  opacity: 0.6;
  text-decoration: line-through;
}

.session-time {
  font-size: 12px;
  color: #666;
  margin-bottom: 5px;
  display: flex;
  align-items: center;
  gap: 5px;
}

.session-time i {
  color: var(--secondary-color);
  font-size: 11px;
}

.session-subject {
  font-weight: 600;
  color: var(--dark-color);
  margin-bottom: 5px;
  font-size: 14px;
}

.session-code {
  font-size: 11px;
  color: var(--secondary-color);
  margin-bottom: 8px;
}

.session-details {
  font-size: 11px;
  color: #666;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.session-details i {
  width: 14px;
  color: var(--primary-color);
  margin-right: 3px;
}

.session-detail-item {
  display: flex;
  align-items: center;
  gap: 5px;
}

.study-mode-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 9px;
  font-weight: 600;
  margin-left: 5px;
}

.study-mode-fulltime {
  background: #e3f2fd;
  color: #1565c0;
}

.study-mode-parttime {
  background: #fff3e0;
  color: #ef6c00;
}

.empty-day {
  text-align: center;
  padding: 30px 15px;
  color: #aaa;
  font-size: 13px;
}

.empty-day i {
  font-size: 30px;
  margin-bottom: 10px;
  color: #ddd;
}

/* ==============================
   MODAL STYLES - from courses.php
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
  max-width: 700px;
  max-height: 90vh;
  overflow-y: auto;
  padding: 30px;
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
  margin-bottom: 25px;
  font-size: 22px;
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

.details-table {
  width: 100%;
  border-collapse: collapse;
}

.details-table tr {
  border-bottom: 1px solid #eee;
}

.details-table tr:last-child {
  border-bottom: none;
}

.details-table td {
  padding: 12px 15px;
  vertical-align: top;
}

.details-table td:first-child {
  font-weight: 600;
  color: var(--dark-color);
  width: 140px;
}

.details-table td:last-child {
  color: #555;
}

.study-mode-badge-detail {
  display: inline-flex;
  align-items: center;
  padding: 4px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
}

.study-mode-badge-detail.fulltime {
  background: #e3f2fd;
  color: #1565c0;
}

.study-mode-badge-detail.parttime {
  background: #fff3e0;
  color: #ef6c00;
}

.close-btn {
  background: var(--secondary-color);
  color: white;
  border: none;
  padding: 12px 30px;
  border-radius: 8px;
  font-weight: 500;
  cursor: pointer;
  margin-top: 20px;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all 0.2s;
}

.close-btn:hover {
  background: #005fa3;
}

/* ==============================
   EMPTY STATE - from courses.php
============================== */
.empty-state {
  text-align: center;
  padding: 80px 20px;
  color: #666;
  background: var(--white);
  border-radius: 10px;
  box-shadow: 0 3px 10px rgba(0,0,0,0.05);
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
  color: #999;
  max-width: 400px;
  margin: 0 auto;
}

/* ==============================
   RESPONSIVE DESIGN - from courses.php
============================== */
@media (max-width: 1200px) {
  .schedule-grid {
    grid-template-columns: repeat(4, 1fr);
  }
}

@media (max-width: 992px) {
  .schedule-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

@media (max-width: 768px) {
  .main-content {
    margin-left: 0 !important;
    padding: 15px;
  }
  
  .page-header {
    flex-direction: column;
    align-items: stretch;
    gap: 15px;
    padding: 15px;
  }
  
  .page-header h1 {
    font-size: 22px;
  }
  
  .role-badge {
    display: inline-block;
    margin-left: 0;
    margin-top: 5px;
  }
  
  .filter-form {
    grid-template-columns: 1fr;
  }
  
  .filters-container {
    padding: 15px;
  }
  
  .filter-header {
    margin-bottom: 10px;
  }
  
  .filter-actions {
    flex-direction: column;
    gap: 8px;
  }
  
  .filter-btn {
    width: 100%;
    justify-content: center;
  }
  
  .info-panel {
    flex-direction: column;
    text-align: center;
    gap: 10px;
  }
  
  .info-stats {
    margin-left: 0;
    width: 100%;
    justify-content: center;
  }
  
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  
  .schedule-grid {
    grid-template-columns: 1fr;
    gap: 10px;
  }
  
  .modal-content {
    padding: 20px 15px;
    max-width: 95%;
    margin: 10px;
  }
  
  .details-table td {
    display: block;
    width: 100%;
    padding: 8px 10px;
  }
  
  .details-table td:first-child {
    font-weight: 600;
    padding-bottom: 0;
  }
}

@media (max-width: 480px) {
  .page-header h1 {
    font-size: 20px;
  }
  
  .page-header h1 i {
    padding: 8px;
    font-size: 18px;
  }
  
  .stat-card {
    padding: 15px;
  }
  
  .stat-icon {
    width: 40px;
    height: 40px;
    font-size: 20px;
  }
  
  .stat-info h3 {
    font-size: 22px;
  }
  
  .stats-grid {
    grid-template-columns: 1fr;
  }
  
  .modal-content h2 {
    font-size: 20px;
    margin-bottom: 20px;
  }
  
  .close-modal {
    width: 36px;
    height: 36px;
    font-size: 24px;
  }
}

/* Landscape orientation adjustments */
@media (max-width: 768px) and (orientation: landscape) {
  .modal-content {
    max-height: 80vh;
  }
}

/* Print optimizations */
@media print {
  .main-content {
    margin: 0 !important;
    padding: 0 !important;
  }
  
  .page-header button,
  .filter-form,
  .modal,
  .view-btn,
  .info-panel i {
    display: none !important;
  }
  
  .day-column {
    break-inside: avoid;
    page-break-inside: avoid;
    border: 1px solid #ddd;
    box-shadow: none;
  }
  
  .day-header {
    -webkit-print-color-adjust: exact;
    color-adjust: exact;
  }
}

/* ==============================
   SCROLLBAR STYLING - from courses.php
============================== */
.table-responsive::-webkit-scrollbar,
.modal-content::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

.table-responsive::-webkit-scrollbar-track,
.modal-content::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb,
.modal-content::-webkit-scrollbar-thumb {
  background: var(--secondary-color);
  border-radius: 4px;
}

.table-responsive::-webkit-scrollbar-thumb:hover,
.modal-content::-webkit-scrollbar-thumb:hover {
  background: var(--primary-color);
}

/* ==============================
   ANIMATIONS - from courses.php
============================== */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

.stat-card,
.day-column {
  animation: fadeIn 0.4s ease forwards;
}

.stat-card:nth-child(1) { animation-delay: 0.05s; }
.stat-card:nth-child(2) { animation-delay: 0.1s; }
.stat-card:nth-child(3) { animation-delay: 0.15s; }
.stat-card:nth-child(4) { animation-delay: 0.2s; }
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1>
      <i class="fas fa-calendar-alt"></i> My Schedule
      <span class="role-badge"><?= htmlspecialchars($role_display) ?></span>
    </h1>
  </div>
  

  <!-- ✅ STATS CARDS -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-calendar-week"></i></div>
      <div class="stat-info">
        <h3><?= $total_sessions ?></h3>
        <p>Weekly Sessions</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-book-open"></i></div>
      <div class="stat-info">
        <h3><?= $total_subjects ?></h3>
        <p>Subjects</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-users"></i></div>
      <div class="stat-info">
        <h3><?= $total_classes ?></h3>
        <p>Classes</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-info">
        <h3><?= $today_sessions ?></h3>
        <p>Today</p>
      </div>
    </div>
  </div>
  
  <!-- ✅ FILTERS SECTION -->
  <div class="filters-container">
    <div class="filter-header">
        <h3><i class="fas fa-filter"></i> Filter Schedule</h3>
    </div>
    
    <form method="GET" class="filter-form" id="filterForm">
        <!-- Search Input -->
        <div class="filter-group">
            <label for="search">Search</label>
            <div style="position:relative;">
                <input type="text" 
                       id="search" 
                       name="search" 
                       class="filter-input" 
                       placeholder="Subject, class..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>
        </div>
        
        <!-- Academic Year Filter -->
        <div class="filter-group">
            <label for="academic_year">Academic Year</label>
            <div style="position:relative;">
                <select id="academic_year" name="academic_year" class="filter-input">
                    <option value="">All Years</option>
                    <?php foreach($academic_years as $year): ?>
                    <option value="<?= $year['academic_year_id'] ?>" 
                        <?= $academic_year_filter == $year['academic_year_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($year['year_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Academic Term Filter -->
        <div class="filter-group">
            <label for="academic_term">Academic Term</label>
            <div style="position:relative;">
                <select id="academic_term" name="academic_term" class="filter-input">
                    <option value="">All Terms</option>
                    <?php foreach($academic_terms as $term): ?>
                    <option value="<?= $term['academic_term_id'] ?>" 
                        <?= $academic_term_filter == $term['academic_term_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($term['term_name']) ?> (<?= htmlspecialchars($term['year_name']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Campus Filter -->
        <div class="filter-group">
            <label for="campus">Campus</label>
            <div style="position:relative;">
                <select id="campus" name="campus" class="filter-input">
                    <option value="">All Campuses</option>
                    <?php foreach($campuses as $campus): ?>
                    <option value="<?= $campus['campus_id'] ?>" 
                        <?= $campus_filter == $campus['campus_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($campus['campus_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Class Filter -->
        <div class="filter-group">
            <label for="class">Class</label>
            <div style="position:relative;">
                <select id="class" name="class" class="filter-input">
                    <option value="">All Classes</option>
                    <?php foreach($classes as $class): ?>
                    <option value="<?= $class['class_id'] ?>" 
                        <?= $class_filter == $class['class_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($class['class_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Day Filter -->
        <div class="filter-group">
            <label for="day">Day of Week</label>
            <div style="position:relative;">
                <select id="day" name="day" class="filter-input">
                    <option value="">All Days</option>
                    <?php foreach($days_of_week as $day): ?>
                    <option value="<?= $day ?>" 
                        <?= $day_filter == $day ? 'selected' : '' ?>>
                        <?= $days_full[$day] ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Status Filter -->
        <div class="filter-group">
            <label for="status">Status</label>
            <div style="position:relative;">
                <select id="status" name="status" class="filter-input">
                    <option value="">All Status</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
        </div>
        
        <!-- Filter Actions -->
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

  <!-- ✅ WEEKLY SCHEDULE GRID -->
  <?php if(!empty($timetable_entries)): ?>
    <div class="schedule-grid">
      <?php 
      $day_order = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
      foreach ($day_order as $day): 
          $sessions = $weekly_schedule[$day] ?? [];
          $is_today = ($day === date('D'));
      ?>
        <div class="day-column">
          <div class="day-header <?= $is_today ? 'today' : '' ?>">
            <h3><?= $days_full[$day] ?></h3>
            <p><?= count($sessions) ?> session(s)</p>
            <?php if ($is_today): ?>
              <span class="today-badge">Today</span>
            <?php endif; ?>
          </div>
          <div class="day-content">
            <?php if (!empty($sessions)): ?>
              <?php foreach ($sessions as $session): ?>
                <div class="session-card <?= $session['tt_status'] ?>" 
                     onclick='viewSession(<?= json_encode($session) ?>)'>
                  <div class="session-time">
                    <i class="far fa-clock"></i>
                    <?= $session['start_time_formatted'] ?? date('h:i A', strtotime($session['start_time'])) ?> - 
                    <?= $session['end_time_formatted'] ?? date('h:i A', strtotime($session['end_time'])) ?>
                  </div>
                  <div class="session-subject"><?= htmlspecialchars($session['subject_name'] ?? 'Unknown') ?></div>
                  <div class="session-code"><?= htmlspecialchars($session['subject_code'] ?? '') ?></div>
                  <div class="session-details">
                    <div class="session-detail-item">
                      <i class="fas fa-users"></i>
                      <span><?= htmlspecialchars($session['class_name'] ?? 'N/A') ?></span>
                      <?php if (!empty($session['study_mode'])): ?>
                        <span class="study-mode-badge study-mode-<?= strtolower(str_replace('-', '', $session['study_mode'])) ?>">
                          <?= $session['study_mode'] ?>
                        </span>
                      <?php endif; ?>
                    </div>
                    <div class="session-detail-item">
                      <i class="fas fa-door-open"></i>
                      <span><?= htmlspecialchars($session['room_name'] ?? $session['room_code'] ?? 'No Room') ?></span>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="empty-day">
                <i class="fas fa-calendar-times"></i>
                <p>No classes</p>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty-state">
      <i class="fas fa-calendar-alt"></i>
      <h3>Ma jiro Jadwal</h3>
      <p>
        Waxaa la eegayaa inaadan wali jadwal lagugu talagalin. 
        Fadlan la xidhiidh maamulka kuliyadaada.
      </p>
    </div>
  <?php endif; ?>
</div>

<!-- ✅ VIEW MODAL -->
<div class="modal" id="viewModal">
  <div class="modal-content">
    <button class="close-modal" onclick="closeModal('viewModal')">&times;</button>
    <h2><i class="fas fa-info-circle"></i> Session Details</h2>
    
    <div style="padding: 10px 0;" id="modalContent">
      <!-- Content will be filled by JavaScript -->
    </div>
    
    <div style="text-align: center;">
      <button class="close-btn" onclick="closeModal('viewModal')">
        <i class="fas fa-times"></i> Close
      </button>
    </div>
  </div>
</div>

<script>
// ===========================================
// FILTER FUNCTIONS
// ===========================================
let searchTimeout;
document.getElementById('search')?.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        document.getElementById('filterForm').submit();
    }, 500);
});

function clearFilters() {
    document.getElementById('search').value = '';
    document.getElementById('academic_year').value = '';
    document.getElementById('academic_term').value = '';
    document.getElementById('campus').value = '';
    document.getElementById('class').value = '';
    document.getElementById('day').value = '';
    document.getElementById('status').value = '';
    
    document.getElementById('filterForm').submit();
}

// ===========================================
// MODAL FUNCTIONS
// ===========================================
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        document.body.style.paddingRight = window.innerWidth - document.documentElement.clientWidth + 'px';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = 'auto';
        document.body.style.paddingRight = '';
    }
}

function viewSession(session) {
    openModal('viewModal');
    
    const dayMap = {
        'Sun': 'Sunday', 'Mon': 'Monday', 'Tue': 'Tuesday',
        'Wed': 'Wednesday', 'Thu': 'Thursday', 'Fri': 'Friday', 'Sat': 'Saturday'
    };
    const dayName = dayMap[session.day_of_week] || session.day_of_week;
    
    const timeDisplay = session.start_time_formatted && session.end_time_formatted ?
        `${session.start_time_formatted} - ${session.end_time_formatted}` :
        `${new Date('1970-01-01T' + session.start_time).toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit', hour12: true})} - 
         ${new Date('1970-01-01T' + session.end_time).toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit', hour12: true})}`;
    
    const roomDisplay = session.room_name ? 
        `${session.room_name} (${session.room_code || ''}) ${session.building_name ? '- ' + session.building_name : ''}` : 
        (session.room_code || 'No room assigned');
    
    const termDates = session.term_start_formatted && session.term_end_formatted ?
        `${session.term_start_formatted} to ${session.term_end_formatted}` : 'N/A';
    
    const studyModeBadge = session.study_mode ? 
        `<span class="study-mode-badge-detail ${session.study_mode === 'Full-Time' ? 'fulltime' : 'parttime'}">
            <i class="fas fa-clock"></i> ${session.study_mode}
        </span>` : 'N/A';
    
    const modalHtml = `
        <table class="details-table">
            <tr>
                <td>Day:</td>
                <td><strong>${dayName}</strong></td>
            </tr>
            <tr>
                <td>Time:</td>
                <td>${timeDisplay}</td>
            </tr>
            <tr>
                <td>Subject:</td>
                <td><strong>${session.subject_name || 'N/A'}</strong> (${session.subject_code || ''})</td>
            </tr>
            <tr>
                <td>Credit Hours:</td>
                <td>${session.credit_hours || 0} CH</td>
            </tr>
            <tr>
                <td>Class:</td>
                <td>${session.class_name || 'N/A'} ${studyModeBadge}</td>
            </tr>
            <tr>
                <td>Program:</td>
                <td>${session.program_name || 'N/A'} (${session.program_code || ''})</td>
            </tr>
            <tr>
                <td>Department:</td>
                <td>${session.department_name || 'N/A'}</td>
            </tr>
            <tr>
                <td>Faculty:</td>
                <td>${session.faculty_name || 'N/A'}</td>
            </tr>
            <tr>
                <td>Campus:</td>
                <td>${session.campus_name || 'N/A'}</td>
            </tr>
            <tr>
                <td>Room:</td>
                <td>${roomDisplay}</td>
            </tr>
            <tr>
                <td>Academic Term:</td>
                <td>${session.term_name || 'N/A'} (${session.year_name || 'N/A'})</td>
            </tr>
            <tr>
                <td>Term Dates:</td>
                <td>${termDates}</td>
            </tr>
            <tr>
                <td>Semester:</td>
                <td>${session.semester_name || 'N/A'}</td>
            </tr>
            <tr>
                <td>Status:</td>
                <td><span class="status-badge status-${session.tt_status}">${session.tt_status}</span></td>
            </tr>
        </table>
    `;
    
    document.getElementById('modalContent').innerHTML = modalHtml;
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach(modal => {
            modal.classList.remove('show');
            document.body.style.overflow = 'auto';
            document.body.style.paddingRight = '';
        });
    }
}

// ===========================================
// KEYBOARD SHORTCUTS
// ===========================================
document.addEventListener('keydown', function(e) {
    // Escape key closes modals
    if (e.key === 'Escape') {
        const openModals = document.querySelectorAll('.modal.show');
        openModals.forEach(modal => {
            modal.classList.remove('show');
        });
        document.body.style.overflow = 'auto';
        document.body.style.paddingRight = '';
    }
    
    // Ctrl + F focuses on search
    if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        document.getElementById('search').focus();
    }
});
</script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>