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
$study_mode_filter = $_GET['study_mode'] ?? '';

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

// Maalinta tusmada (array for day filtering)
$days_of_week = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

// Study modes
$study_modes = ['Full-Time', 'Part-Time'];

// ===========================================
// HEL MACLUUMAADKA COURSES-KA TEACHER-KA KA TIMETABLE
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
        c.study_mode,  /* Full-Time ama Part-Time */
        
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
        sem.semester_name,
        
        t.teacher_id as t_teacher_id,
        t.teacher_name,
        t.email as teacher_email
        
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
    LEFT JOIN teachers t ON tt.teacher_id = t.teacher_id
    WHERE tt.teacher_id = ?
";

$params = [$teacher_id];

// Search filter
if (!empty($search)) {
    $query .= " AND (s.subject_name LIKE ? OR s.subject_code LIKE ? OR c.class_name LIKE ? OR p.program_name LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
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

// Study Mode filter
if (!empty($study_mode_filter)) {
    $query .= " AND c.study_mode = ?";
    $params[] = $study_mode_filter;
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

// Group by subject for summary
$subjects = [];
foreach ($timetable_entries as $entry) {
    $subject_id = $entry['subject_id'];
    if (!isset($subjects[$subject_id])) {
        // Determine study mode badge color
        $study_mode_class = '';
        $study_mode_display = '';
        if (!empty($entry['study_mode'])) {
            $study_mode_display = $entry['study_mode'];
            $study_mode_class = $entry['study_mode'] === 'Full-Time' ? 'study-mode-fulltime' : 'study-mode-parttime';
        }
        
        $subjects[$subject_id] = [
            'subject_id' => $entry['subject_id'],
            'subject_name' => $entry['subject_name'],
            'subject_code' => $entry['subject_code'],
            'credit_hours' => $entry['credit_hours'],
            'class_name' => $entry['class_name'],
            'study_mode' => $entry['study_mode'],
            'study_mode_display' => $study_mode_display,
            'study_mode_class' => $study_mode_class,
            'program_name' => $entry['program_name'],
            'campus_name' => $entry['campus_name'],
            'academic_term' => $entry['term_name'],
            'academic_year' => $entry['year_name'],
            'teacher_name' => $entry['teacher_name'],
            'sessions' => []
        ];
    }
    $subjects[$subject_id]['sessions'][] = $entry;
}

// Xisaabi wadarta credit hours
$total_credit_hours = 0;
foreach ($subjects as $subject) {
    $total_credit_hours += intval($subject['credit_hours'] ?? 0);
}

// Hel tirada campuses-ka
$unique_campuses = [];
foreach ($timetable_entries as $entry) {
    if (!empty($entry['campus_name'])) {
        $unique_campuses[$entry['campus_name']] = true;
    }
}
$campus_count = count($unique_campuses);

$role_display = "Teacher (Macalin)";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My Courses | Teacher Dashboard | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ===========================================
   CSS VARIABLES & RESET
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
   INFO PANEL
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
   FILTERS SECTION
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
   STUDY MODE BADGES
============================== */
.study-mode-badge {
  display: inline-flex;
  align-items: center;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
  margin-left: 8px;
}

.study-mode-fulltime {
  background: #e3f2fd;
  color: #1565c0;
  border: 1px solid #90caf9;
}

.study-mode-parttime {
  background: #fff3e0;
  color: #ef6c00;
  border: 1px solid #ffb74d;
}

.study-mode-icon {
  margin-right: 4px;
  font-size: 10px;
}

.class-with-mode {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
}

/* ==============================
   STATS CARDS
============================== */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
   COURSE CARDS
============================== */
.courses-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
  gap: 20px;
  margin-bottom: 30px;
}

.course-card {
  background: var(--white);
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
  transition: transform 0.3s ease, box-shadow 0.3s ease;
  border: 1px solid var(--border-color);
  display: flex;
  flex-direction: column;
}

.course-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
}

.course-header {
  background: linear-gradient(135deg, var(--secondary-color), var(--primary-color));
  color: white;
  padding: 20px;
  position: relative;
}

.course-header h3 {
  font-size: 18px;
  font-weight: 600;
  margin-bottom: 8px;
  padding-right: 60px;
}

.course-code {
  font-size: 14px;
  opacity: 0.9;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 5px;
}

.course-code i {
  font-size: 12px;
}

.credit-badge {
  position: absolute;
  top: 15px;
  right: 15px;
  background: rgba(255, 255, 255, 0.2);
  padding: 5px 12px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
  backdrop-filter: blur(5px);
}

.course-body {
  padding: 20px;
  flex: 1;
}

.course-info-item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  margin-bottom: 12px;
  color: #555;
  font-size: 14px;
}

.course-info-item i {
  width: 18px;
  color: var(--secondary-color);
  margin-top: 3px;
}

.course-info-item .label {
  font-weight: 600;
  color: var(--dark-color);
  min-width: 80px;
}

.course-info-item .value {
  flex: 1;
}

.class-display {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
}

.class-name {
  font-weight: 500;
}

.study-mode-indicator {
  display: inline-flex;
  align-items: center;
  padding: 3px 8px;
  border-radius: 16px;
  font-size: 10px;
  font-weight: 600;
}

.study-mode-indicator.fulltime {
  background: #e3f2fd;
  color: #1565c0;
}

.study-mode-indicator.parttime {
  background: #fff3e0;
  color: #ef6c00;
}

.session-list {
  margin-top: 15px;
  border-top: 1px dashed #ddd;
  padding-top: 15px;
}

.session-title {
  font-weight: 600;
  color: var(--dark-color);
  margin-bottom: 10px;
  font-size: 14px;
}

.session-item {
  background: #f8f9fa;
  border-radius: 6px;
  padding: 8px 12px;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 13px;
}

.session-day {
  background: var(--secondary-color);
  color: white;
  padding: 3px 8px;
  border-radius: 4px;
  font-weight: 500;
  min-width: 45px;
  text-align: center;
  font-size: 11px;
}

.session-time {
  color: var(--dark-color);
  font-weight: 500;
}

.session-room {
  color: #666;
}

.session-status {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  display: inline-block;
  margin-left: 5px;
}

.status-active {
  background: var(--primary-color);
}

.status-inactive {
  background: var(--danger-color);
}

.course-footer {
  background: #f8f9fa;
  padding: 15px 20px;
  border-top: 1px solid #eee;
  display: flex;
  justify-content: flex-end;
}

.view-btn {
  background: var(--secondary-color);
  color: white;
  border: none;
  padding: 8px 20px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all 0.2s;
}

.view-btn:hover {
  background: #005fa3;
  transform: translateY(-2px);
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

.sessions-table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 10px;
}

.sessions-table th {
  background: #f5f5f5;
  padding: 10px;
  text-align: left;
  font-size: 13px;
  font-weight: 600;
  color: var(--dark-color);
}

.sessions-table td {
  padding: 10px;
  border-bottom: 1px solid #eee;
  font-size: 13px;
}

.sessions-table tr:last-child td {
  border-bottom: none;
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
   EMPTY STATE
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
    grid-template-columns: repeat(3, 1fr);
  }
  
  .courses-grid {
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
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
  
  .courses-grid {
    grid-template-columns: 1fr;
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
  
  .session-item {
    flex-wrap: wrap;
    gap: 5px;
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
  
  .course-header h3 {
    font-size: 16px;
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
  
  .course-card {
    break-inside: avoid;
    page-break-inside: avoid;
    border: 1px solid #ddd;
    box-shadow: none;
  }
  
  .course-header {
    -webkit-print-color-adjust: exact;
    color-adjust: exact;
  }
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <div class="page-header">
    <h1>
      <i class="fas fa-chalkboard-teacher"></i> My Courses
    </h1>
  </div>
  

  
  <!-- ✅ STATS CARDS -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-book-open"></i></div>
      <div class="stat-info">
        <h3><?= count($subjects) ?></h3>
        <p>Courses</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-calendar-week"></i></div>
      <div class="stat-info">
        <h3><?= count($timetable_entries) ?></h3>
        <p>Weekly Sessions</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-clock"></i></div>
      <div class="stat-info">
        <h3><?= $total_credit_hours ?></h3>
        <p>Total Credit Hours</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon"><i class="fas fa-building"></i></div>
      <div class="stat-info">
        <h3><?= $campus_count ?></h3>
        <p>Campuses</p>
      </div>
    </div>
  </div>
  
  <!-- ✅ FILTERS SECTION -->
  <div class="filters-container">
    <div class="filter-header">
        <h3><i class="fas fa-filter"></i> Filter Courses</h3>
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
                       placeholder="Course name, code, class..."
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
        
        <!-- Day Filter -->
        <div class="filter-group">
            <label for="day">Day of Week</label>
            <div style="position:relative;">
                <select id="day" name="day" class="filter-input">
                    <option value="">All Days</option>
                    <?php foreach($days_of_week as $day): ?>
                    <option value="<?= $day ?>" 
                        <?= $day_filter == $day ? 'selected' : '' ?>>
                        <?php
                        switch($day) {
                            case 'Sun': echo 'Sunday'; break;
                            case 'Mon': echo 'Monday'; break;
                            case 'Tue': echo 'Tuesday'; break;
                            case 'Wed': echo 'Wednesday'; break;
                            case 'Thu': echo 'Thursday'; break;
                            case 'Fri': echo 'Friday'; break;
                            case 'Sat': echo 'Saturday'; break;
                        }
                        ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Study Mode Filter -->
        <div class="filter-group">
            <label for="study_mode">Study Mode</label>
            <div style="position:relative;">
                <select id="study_mode" name="study_mode" class="filter-input">
                    <option value="">All Modes</option>
                    <?php foreach($study_modes as $mode): ?>
                    <option value="<?= $mode ?>" 
                        <?= $study_mode_filter == $mode ? 'selected' : '' ?>>
                        <?= $mode ?>
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

  <!-- ✅ COURSES GRID -->
  <?php if(!empty($timetable_entries)): ?>
    <?php if(!empty($subjects)): ?>
      <div class="courses-grid">
        <?php foreach($subjects as $subject): ?>
          <div class="course-card">
            <div class="course-header">
              <h3><?= htmlspecialchars($subject['subject_name'] ?? 'Unknown Course') ?></h3>
              <div class="course-code">
                <i class="fas fa-code"></i> <?= htmlspecialchars($subject['subject_code'] ?? 'N/A') ?>
              </div>
              <span class="credit-badge">
                <i class="fas fa-star"></i> <?= $subject['credit_hours'] ?? 0 ?> CH
              </span>
            </div>
            
            <div class="course-body">
              <div class="course-info-item">
                <i class="fas fa-users"></i>
                <span class="label">Class:</span>
                <span class="value">
                  <div class="class-display">
                    <span class="class-name"><?= htmlspecialchars($subject['class_name'] ?? 'N/A') ?></span>
                    <?php if(!empty($subject['study_mode'])): ?>
                      <span class="study-mode-indicator <?= strtolower($subject['study_mode']) === 'full-time' ? 'fulltime' : 'parttime' ?>">
                        <i class="fas <?= $subject['study_mode'] === 'Full-Time' ? 'fa-clock' : 'fa-clock' ?> study-mode-icon"></i>
                        <?= htmlspecialchars($subject['study_mode']) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </span>
              </div>
              
              <div class="course-info-item">
                <i class="fas fa-graduation-cap"></i>
                <span class="label">Program:</span>
                <span class="value"><?= htmlspecialchars($subject['program_name'] ?? 'N/A') ?></span>
              </div>
              
              <div class="course-info-item">
                <i class="fas fa-building"></i>
                <span class="label">Campus:</span>
                <span class="value"><?= htmlspecialchars($subject['campus_name'] ?? 'N/A') ?></span>
              </div>
              
              <div class="course-info-item">
                <i class="fas fa-calendar-alt"></i>
                <span class="label">Term:</span>
                <span class="value"><?= htmlspecialchars($subject['academic_term'] ?? 'N/A') ?> (<?= htmlspecialchars($subject['academic_year'] ?? 'N/A') ?>)</span>
              </div>
              
              <?php if(count($subject['sessions']) > 0): ?>
                <div class="session-list">
                  <div class="session-title">
                    <i class="fas fa-clock"></i> Weekly Sessions (<?= count($subject['sessions']) ?>)
                  </div>
                  <?php foreach(array_slice($subject['sessions'], 0, 2) as $session): ?>
                    <div class="session-item">
                      <span class="session-day"><?= $session['day_of_week'] ?></span>
                      <span class="session-time">
                        <?php 
                        if (!empty($session['start_time_formatted'])) {
                            echo $session['start_time_formatted'] . ' - ' . $session['end_time_formatted'];
                        } else {
                            echo date('h:i A', strtotime($session['start_time'])) . ' - ' . date('h:i A', strtotime($session['end_time']));
                        }
                        ?>
                      </span>
                      <span class="session-room">
                        <i class="fas fa-door-open"></i> <?= htmlspecialchars($session['room_name'] ?? $session['room_code'] ?? 'N/A') ?>
                      </span>
                      <span class="session-status status-<?= $session['tt_status'] ?>"></span>
                    </div>
                  <?php endforeach; ?>
                  <?php if(count($subject['sessions']) > 2): ?>
                    <div style="text-align: center; margin-top: 8px; font-size: 12px; color: #666;">
                      +<?= count($subject['sessions']) - 2 ?> more sessions
                    </div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            
            <div class="course-footer">
              <button class="view-btn" onclick='viewCourse(<?= json_encode($subject) ?>)'>
                <i class="fas fa-eye"></i> View Details
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty-state">
        <i class="fas fa-chalkboard-teacher"></i>
        <h3>Ma jiraan courses</h3>
        <p>
          Waxaa la eegayaa inaadan wali courses lagugu talagalin jadwalka (timetable). 
          Fadlan la xidhiidh maamulka kuliyadaada.
        </p>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <div class="empty-state">
      <i class="fas fa-chalkboard-teacher"></i>
      <h3>Ma jiraan courses</h3>
      <p>
        Waxaa la eegayaa inaadan wali courses lagugu talagalin jadwalka (timetable). 
        Fadlan la xidhiidh maamulka kuliyadaada.
      </p>
      <?php if (isset($_GET['debug'])): ?>
        <div style="margin-top: 20px; padding: 10px; background: #f0f0f0; text-align: left; font-size: 12px;">
          <p><strong>Debug Info:</strong></p>
          <p>Teacher ID: <?= $teacher_id ?></p>
          <p>User Role: <?= $role ?></p>
          <p>Linked ID: <?= $user['linked_id'] ?? 'None' ?></p>
          <p>User Email: <?= $user['email'] ?? 'None' ?></p>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ✅ VIEW MODAL -->
<div class="modal" id="viewModal">
  <div class="modal-content">
    <button class="close-modal" onclick="closeModal('viewModal')">&times;</button>
    <h2><i class="fas fa-info-circle"></i> Course Details</h2>
    
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

<!-- ✅ DEBUG MODE (ka saar markaad dhamayso) -->
<?php if (isset($_GET['debug'])): ?>
<div style="background: #fff; padding: 20px; margin: 20px; border: 1px solid #ccc; border-radius: 5px;">
  <h3>Debug Information</h3>
  <p><strong>Teacher ID:</strong> <?= $teacher_id ?></p>
  <p><strong>User Role:</strong> <?= $role ?></p>
  <p><strong>Linked ID:</strong> <?= $user['linked_id'] ?? 'None' ?></p>
  <p><strong>User Email:</strong> <?= $user['email'] ?? 'None' ?></p>
  <p><strong>User Username:</strong> <?= $user['username'] ?? 'None' ?></p>
  <p><strong>Number of Timetable Entries:</strong> <?= count($timetable_entries) ?></p>
  <?php if (count($timetable_entries) > 0): ?>
    <h4>First Entry:</h4>
    <pre><?= print_r($timetable_entries[0], true) ?></pre>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ✅ ALERT POPUP (if any messages) -->
<?php if(!empty($message)): ?>
<div id="popup" class="alert-popup <?= $type ?> show">
  <span class="alert-icon"><?= $type==='success' ? '✓' : '✗' ?></span>
  <div class="alert-message"><?= $message ?></div>
</div>
<?php endif; ?>

<script>
// ===========================================
// RESPONSIVE LAYOUT HANDLING
// ===========================================
// Auto-hide alert after 5 seconds
setTimeout(function() {
    const alert = document.querySelector('.alert-popup.show');
    if (alert) {
        alert.classList.remove('show');
    }
}, 5000);

// Close alert on click
document.addEventListener('click', function(e) {
    if (e.target.closest('.alert-popup.show')) {
        e.target.closest('.alert-popup.show').classList.remove('show');
    }
});

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
    document.getElementById('day').value = '';
    document.getElementById('study_mode').value = '';
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

function viewCourse(subject) {
    openModal('viewModal');
    
    // Build sessions HTML
    let sessionsHtml = '';
    if (subject.sessions && subject.sessions.length > 0) {
        sessionsHtml = '<h3 style="margin: 20px 0 10px; font-size: 16px;">Weekly Sessions</h3>';
        sessionsHtml += '<table class="sessions-table">';
        sessionsHtml += '<thead><tr><th>Day</th><th>Time</th><th>Room</th><th>Status</th></tr></thead>';
        sessionsHtml += '<tbody>';
        
        subject.sessions.forEach(session => {
            const dayMap = {
                'Sun': 'Sunday',
                'Mon': 'Monday',
                'Tue': 'Tuesday',
                'Wed': 'Wednesday',
                'Thu': 'Thursday',
                'Fri': 'Friday',
                'Sat': 'Saturday'
            };
            const dayName = dayMap[session.day_of_week] || session.day_of_week;
            
            let timeDisplay = '';
            if (session.start_time_formatted) {
                timeDisplay = session.start_time_formatted + ' - ' + session.end_time_formatted;
            } else {
                const startTime = session.start_time ? new Date('1970-01-01T' + session.start_time).toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit', hour12: true}) : 'N/A';
                const endTime = session.end_time ? new Date('1970-01-01T' + session.end_time).toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit', hour12: true}) : 'N/A';
                timeDisplay = startTime + ' - ' + endTime;
            }
            
            const roomDisplay = session.room_name || session.room_code || 'N/A';
            const statusClass = session.tt_status === 'active' ? 'status-active' : (session.tt_status === 'inactive' ? 'status-inactive' : '');
            
            sessionsHtml += '<tr>';
            sessionsHtml += `<td><strong>${dayName}</strong></td>`;
            sessionsHtml += `<td>${timeDisplay}</td>`;
            sessionsHtml += `<td>${roomDisplay}</td>`;
            sessionsHtml += `<td><span class="status-badge status-${session.tt_status}">${session.tt_status}</span></td>`;
            sessionsHtml += '</tr>';
        });
        
        sessionsHtml += '</tbody></table>';
    }
    
    const programDisplay = subject.program_name ? 
        `${subject.program_name} (${subject.program_code || ''})` : 'N/A';
    
    const termDates = subject.term_start_formatted && subject.term_end_formatted ? 
        `${subject.term_start_formatted} to ${subject.term_end_formatted}` : 'N/A';
    
    const studyModeBadge = subject.study_mode ? 
        `<span class="study-mode-badge-detail ${subject.study_mode === 'Full-Time' ? 'fulltime' : 'parttime'}">
            <i class="fas fa-clock study-mode-icon"></i> ${subject.study_mode}
        </span>` : 'N/A';
    
    const modalHtml = `
        <table class="details-table">
            <tr>
                <td>Course Code:</td>
                <td><strong>${subject.subject_code || 'N/A'}</strong></td>
            </tr>
            <tr>
                <td>Course Name:</td>
                <td>${subject.subject_name || 'N/A'}</td>
            </tr>
            <tr>
                <td>Credit Hours:</td>
                <td>${subject.credit_hours || 0} CH</td>
            </tr>
            <tr>
                <td>Class:</td>
                <td>${subject.class_name || 'N/A'} ${studyModeBadge}</td>
            </tr>
            <tr>
                <td>Program:</td>
                <td>${programDisplay}</td>
            </tr>
            <tr>
                <td>Campus:</td>
                <td>${subject.campus_name || 'N/A'}</td>
            </tr>
            <tr>
                <td>Academic Term:</td>
                <td>${subject.academic_term || 'N/A'} (${subject.academic_year || 'N/A'})</td>
            </tr>
            <tr>
                
            </tr>
            <tr>
                <td>Teacher:</td>
                <td>${subject.teacher_name || 'N/A'}</td>
            </tr>
        </table>
        ${sessionsHtml}
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