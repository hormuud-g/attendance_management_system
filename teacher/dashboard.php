<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Redirect if not logged in
if (empty($_SESSION['user'])) {
  header("Location: ../login.php");
  exit;
}

// ✅ User Data
$user = $_SESSION['user'];
$role = $user['role'] ?? '';
$user_id = $user['user_id'] ?? 0;

// ✅ Get user full name
$first_name = $last_name = $display_name = '';
if ($user_id) {
    $stmt = $pdo->prepare("SELECT first_name, last_name, username FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user_data) {
        $first_name = $user_data['first_name'] ?? '';
        $last_name = $user_data['last_name'] ?? '';
        $username = $user_data['username'] ?? '';
        $display_name = (!empty($first_name) && !empty($last_name)) 
            ? ucfirst($first_name) . ' ' . ucfirst($last_name) 
            : ucfirst($username);
    }
}

$name = $display_name ?: ucfirst($user['username'] ?? 'User');

// ✅ Include header
require_once('../includes/header.php');

// ✅ Get teacher-specific data
function getTeacherAttendanceStats($pdo, $teacher_id = null) {
    try {
        // If no specific teacher_id, get stats for all teachers
        $query = "SELECT 
                    COUNT(DISTINCT teacher_id) as total_teachers,
                    SUM(CASE WHEN ta.id IS NOT NULL THEN 1 ELSE 0 END) as present_today,
                    COUNT(DISTINCT CASE WHEN t.day_of_week = DATE_FORMAT(CURDATE(), '%a') THEN t.timetable_id END) as scheduled_today
                  FROM teachers t
                  LEFT JOIN teacher_attendance ta ON t.teacher_id = ta.teacher_id AND ta.date = CURDATE()";
        
        if ($teacher_id) {
            $query .= " WHERE t.teacher_id = ?";
            $stmt = $pdo->prepare($query);
            $stmt->execute([$teacher_id]);
        } else {
            $stmt = $pdo->query($query);
        }
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return ['total_teachers' => 0, 'present_today' => 0, 'scheduled_today' => 0];
    }
}

// ✅ Get teacher's schedule
function getTeacherSchedule($pdo, $teacher_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                t.timetable_id,
                t.day_of_week,
                t.start_time,
                t.end_time,
                s.subject_name,
                s.subject_code,
                c.class_name,
                r.room_name,
                r.building_name,
                aterm.term_name,
                aterm.academic_term_id
            FROM timetable t
            JOIN subject s ON t.subject_id = s.subject_id
            JOIN classes c ON t.class_id = c.class_id
            LEFT JOIN rooms r ON t.room_id = r.room_id
            JOIN academic_term aterm ON t.academic_term_id = aterm.academic_term_id
            WHERE t.teacher_id = ? 
            AND t.status = 'active'
            AND aterm.status = 'active'
            ORDER BY 
                FIELD(t.day_of_week, 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'),
                t.start_time
        ");
        $stmt->execute([$teacher_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// ✅ Get teacher's classes/subjects
function getTeacherClasses($pdo, $teacher_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                s.subject_id,
                s.subject_name,
                s.subject_code,
                c.class_id,
                c.class_name,
                COUNT(DISTINCT se.student_id) as student_count,
                COUNT(DISTINCT t.timetable_id) as sessions_per_week
            FROM subject s
            JOIN timetable t ON s.subject_id = t.subject_id
            JOIN classes c ON t.class_id = c.class_id
            LEFT JOIN student_enroll se ON s.subject_id = se.subject_id 
                AND se.academic_term_id = t.academic_term_id
                AND se.status = 'active'
            WHERE t.teacher_id = ?
                AND t.status = 'active'
            GROUP BY s.subject_id, s.subject_name, s.subject_code, c.class_id, c.class_name
        ");
        $stmt->execute([$teacher_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// ✅ Get today's attendance for teacher
function getTodayAttendance($pdo, $teacher_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                ta.*,
                t.subject_name,
                c.class_name
            FROM teacher_attendance ta
            JOIN timetable tt ON ta.teacher_id = tt.teacher_id
            JOIN subject t ON tt.subject_id = t.subject_id
            JOIN classes c ON tt.class_id = c.class_id
            WHERE ta.teacher_id = ? 
                AND ta.date = CURDATE()
            LIMIT 1
        ");
        $stmt->execute([$teacher_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

// ✅ Get weekly attendance summary
function getWeeklyAttendance($pdo, $teacher_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(date, '%Y-%m-%d') as date,
                DATE_FORMAT(date, '%a') as day_name,
                time_in,
                time_out,
                minutes_worked,
                notes
            FROM teacher_attendance
            WHERE teacher_id = ?
                AND date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            ORDER BY date DESC
        ");
        $stmt->execute([$teacher_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

// ✅ Get teacher info
$teacher_id = null;
$teacher_info = null;
if ($role === 'teacher' && isset($user['linked_id'])) {
    $teacher_id = $user['linked_id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM teachers WHERE teacher_id = ?");
        $stmt->execute([$teacher_id]);
        $teacher_info = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $teacher_info = null;
    }
}

// ✅ Get all data
$attendance_stats = getTeacherAttendanceStats($pdo, $teacher_id);
$teacher_schedule = $teacher_id ? getTeacherSchedule($pdo, $teacher_id) : [];
$teacher_classes = $teacher_id ? getTeacherClasses($pdo, $teacher_id) : [];
$today_attendance = $teacher_id ? getTodayAttendance($pdo, $teacher_id) : [];
$weekly_attendance = $teacher_id ? getWeeklyAttendance($pdo, $teacher_id) : [];

// ✅ Count total students
$total_students = 0;
foreach ($teacher_classes as $class) {
    $total_students += $class['student_count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Dashboard | Hormuud University</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ==============================
   DASHBOARD BASE
   ============================== */
.dashboard-wrapper {
  margin-left: 240px;
  padding: 80px 25px 40px;
  transition: margin-left 0.3s ease;
  min-height: calc(100vh - 130px);
  background: linear-gradient(135deg, #f8fafc 0%, #f0f7ff 100%);
}

body.sidebar-collapsed .dashboard-wrapper {
  margin-left: 60px;
}

/* ==============================
   WELCOME BANNER
   ============================== */
.welcome-section {
  margin-bottom: 30px;
}

.welcome-card {
  background: linear-gradient(135deg, #2196F3 0%, #1976D2 100%);
  color: white;
  border-radius: 20px;
  padding: 35px 40px;
  box-shadow: 
    0 20px 40px rgba(33, 150, 243, 0.2),
    0 10px 20px rgba(33, 150, 243, 0.1),
    inset 0 1px 0 rgba(255, 255, 255, 0.2);
  position: relative;
  overflow: hidden;
}

.welcome-card::before {
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

.welcome-content h1 {
  font-size: 32px;
  font-weight: 700;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 15px;
  text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.welcome-content h1 i {
  background: rgba(255, 255, 255, 0.2);
  padding: 12px;
  border-radius: 12px;
  backdrop-filter: blur(10px);
}

.welcome-content p {
  font-size: 16px;
  opacity: 0.9;
  margin-bottom: 8px;
  font-weight: 300;
}

.welcome-content p strong {
  font-weight: 600;
  background: rgba(255, 255, 255, 0.2);
  padding: 3px 10px;
  border-radius: 6px;
  margin-left: 5px;
}

.welcome-stats {
  display: flex;
  gap: 40px;
  margin-top: 30px;
  flex-wrap: wrap;
}

.stat-item {
  display: flex;
  align-items: center;
  gap: 15px;
  background: rgba(255, 255, 255, 0.15);
  padding: 15px 20px;
  border-radius: 16px;
  backdrop-filter: blur(10px);
  border: 1px solid rgba(255, 255, 255, 0.1);
  transition: all 0.3s ease;
  min-width: 200px;
}

.stat-item:hover {
  transform: translateY(-5px);
  background: rgba(255, 255, 255, 0.25);
  box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
}

.stat-icon {
  width: 60px;
  height: 60px;
  background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.1));
  border-radius: 15px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  border: 1px solid rgba(255, 255, 255, 0.2);
}

.stat-info h3 {
  font-size: 24px;
  font-weight: 700;
  margin: 0 0 5px 0;
}

.stat-info span {
  font-size: 14px;
  opacity: 0.9;
  font-weight: 300;
}

/* ==============================
   TEACHER INFO CARD
   ============================== */
.teacher-info-card {
  background: white;
  border-radius: 20px;
  padding: 30px;
  margin-bottom: 30px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
  display: flex;
  align-items: center;
  gap: 30px;
  position: relative;
  overflow: hidden;
}

.teacher-info-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  width: 6px;
  height: 100%;
  background: linear-gradient(180deg, #2196F3, #1976D2);
}

.teacher-avatar {
  width: 100px;
  height: 100px;
  border-radius: 50%;
  background: linear-gradient(135deg, #2196F3, #1976D2);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 40px;
  color: white;
  box-shadow: 0 10px 20px rgba(33, 150, 243, 0.3);
}

.teacher-details {
  flex: 1;
}

.teacher-details h2 {
  font-size: 28px;
  font-weight: 700;
  color: #333;
  margin-bottom: 10px;
}

.teacher-details p {
  color: #666;
  margin-bottom: 5px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.teacher-details p i {
  width: 20px;
  color: #2196F3;
}

.teacher-badge {
  background: linear-gradient(135deg, #2196F3, #1976D2);
  color: white;
  padding: 8px 20px;
  border-radius: 30px;
  font-size: 14px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

/* ==============================
   ATTENDANCE CARD
   ============================== */
.attendance-card {
  background: white;
  border-radius: 20px;
  padding: 30px;
  margin-bottom: 30px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
  position: relative;
  overflow: hidden;
}

.attendance-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 4px;
  background: linear-gradient(90deg, #2196F3, #4CAF50);
}

.attendance-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 25px;
}

.attendance-header h3 {
  font-size: 20px;
  font-weight: 700;
  color: #333;
  display: flex;
  align-items: center;
  gap: 10px;
}

.attendance-header h3 i {
  color: #2196F3;
}

.attendance-badge {
  background: #e8f5e9;
  color: #4CAF50;
  padding: 8px 15px;
  border-radius: 30px;
  font-size: 14px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 5px;
}

.attendance-badge.warning {
  background: #fff3e0;
  color: #FF9800;
}

.attendance-badge.danger {
  background: #ffebee;
  color: #f44336;
}

.attendance-actions {
  display: flex;
  gap: 10px;
}

.btn-attendance {
  background: linear-gradient(135deg, #2196F3, #1976D2);
  color: white;
  border: none;
  padding: 12px 25px;
  border-radius: 12px;
  font-size: 16px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  transition: all 0.3s ease;
}

.btn-attendance:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 20px rgba(33, 150, 243, 0.3);
}

.btn-attendance.outline {
  background: white;
  color: #2196F3;
  border: 2px solid #2196F3;
}

.btn-attendance.outline:hover {
  background: #2196F3;
  color: white;
}

/* ==============================
   STATS CARDS GRID
   ============================== */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 25px;
  margin-bottom: 40px;
}

.stat-card {
  background: white;
  border-radius: 20px;
  padding: 25px;
  box-shadow: 
    0 10px 30px rgba(0, 0, 0, 0.08),
    0 4px 12px rgba(0, 0, 0, 0.04);
  transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
  border-left: 6px solid;
  position: relative;
  overflow: hidden;
}

.stat-card:hover {
  transform: translateY(-10px) scale(1.02);
  box-shadow: 
    0 20px 40px rgba(0, 0, 0, 0.12),
    0 8px 24px rgba(0, 0, 0, 0.08);
}

.stat-card.classes { --card-color: #2196F3; }
.stat-card.students { --card-color: #4CAF50; }
.stat-card.subjects { --card-color: #FF9800; }
.stat-card.attendance { --card-color: #9C27B0; }

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 20px;
  position: relative;
  z-index: 2;
}

.card-title h3 {
  font-size: 16px;
  font-weight: 600;
  color: #666;
  margin: 0 0 5px 0;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.card-icon {
  width: 60px;
  height: 60px;
  background: linear-gradient(135deg, var(--card-color), color-mix(in srgb, var(--card-color) 70%, white));
  border-radius: 15px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  color: white;
  box-shadow: 0 8px 16px rgba(var(--card-color-rgb), 0.2);
}

.card-numbers .main-count {
  font-size: 36px;
  font-weight: 800;
  color: var(--card-color);
  line-height: 1;
  margin-bottom: 5px;
}

/* ==============================
   SCHEDULE TABLE
   ============================== */
.schedule-section {
  background: white;
  border-radius: 20px;
  padding: 30px;
  margin-bottom: 40px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
}

.schedule-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 25px;
}

.schedule-header h3 {
  font-size: 20px;
  font-weight: 700;
  color: #333;
  display: flex;
  align-items: center;
  gap: 10px;
}

.schedule-header h3 i {
  color: #2196F3;
}

.schedule-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 20px;
}

.schedule-card {
  background: linear-gradient(135deg, #f8fafc 0%, #f0f7ff 100%);
  border-radius: 15px;
  padding: 20px;
  border-left: 4px solid #2196F3;
  transition: all 0.3s ease;
}

.schedule-card:hover {
  transform: translateY(-5px);
  box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
}

.schedule-day {
  font-size: 18px;
  font-weight: 700;
  color: #2196F3;
  margin-bottom: 15px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.schedule-time {
  background: white;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 14px;
  color: #333;
  margin-bottom: 15px;
  display: inline-block;
}

.schedule-subject {
  font-size: 18px;
  font-weight: 600;
  color: #333;
  margin-bottom: 5px;
}

.schedule-class {
  color: #666;
  font-size: 14px;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 5px;
}

.schedule-room {
  color: #999;
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 5px;
}

/* ==============================
   CLASSES TABLE
   ============================== */
.classes-section {
  background: white;
  border-radius: 20px;
  padding: 30px;
  margin-bottom: 40px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
}

.classes-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 25px;
}

.classes-header h3 {
  font-size: 20px;
  font-weight: 700;
  color: #333;
  display: flex;
  align-items: center;
  gap: 10px;
}

.classes-header h3 i {
  color: #2196F3;
}

.classes-table {
  width: 100%;
  border-collapse: collapse;
}

.classes-table th {
  text-align: left;
  padding: 15px;
  background: #f8fafc;
  color: #666;
  font-weight: 600;
  font-size: 14px;
  border-radius: 10px 10px 0 0;
}

.classes-table td {
  padding: 15px;
  border-bottom: 1px solid #eef2f7;
  color: #333;
}

.classes-table tr:hover td {
  background: #f8fafc;
}

.class-badge {
  background: #e3f2fd;
  color: #1976D2;
  padding: 5px 10px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
}

/* ==============================
   WEEKLY ATTENDANCE
   ============================== */
.weekly-attendance {
  background: white;
  border-radius: 20px;
  padding: 30px;
  margin-bottom: 40px;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
}

.weekly-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 25px;
}

.weekly-header h3 {
  font-size: 20px;
  font-weight: 700;
  color: #333;
  display: flex;
  align-items: center;
  gap: 10px;
}

.weekly-header h3 i {
  color: #2196F3;
}

.attendance-timeline {
  display: flex;
  flex-direction: column;
  gap: 15px;
}

.timeline-item {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 15px;
  background: #f8fafc;
  border-radius: 12px;
  transition: all 0.3s ease;
}

.timeline-item:hover {
  background: white;
  box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
}

.timeline-date {
  min-width: 100px;
  font-weight: 600;
  color: #333;
}

.timeline-day {
  font-size: 12px;
  color: #999;
  margin-top: 3px;
}

.timeline-status {
  padding: 5px 15px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 600;
  min-width: 100px;
  text-align: center;
}

.status-present {
  background: #e8f5e9;
  color: #4CAF50;
}

.status-absent {
  background: #ffebee;
  color: #f44336;
}

.status-late {
  background: #fff3e0;
  color: #FF9800;
}

.timeline-time {
  color: #666;
  font-size: 14px;
  display: flex;
  align-items: center;
  gap: 5px;
}

.timeline-hours {
  margin-left: auto;
  background: #e3f2fd;
  color: #1976D2;
  padding: 5px 12px;
  border-radius: 20px;
  font-size: 13px;
  font-weight: 600;
}

/* ==============================
   RESPONSIVE DESIGN
   ============================== */
@media (max-width: 1200px) {
  .stats-grid {
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
  }
}

@media (max-width: 992px) {
  .schedule-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .dashboard-wrapper {
    margin-left: 0 !important;
    padding: 80px 15px 40px;
  }
  
  .welcome-card {
    padding: 25px 20px;
  }
  
  .welcome-content h1 {
    font-size: 24px;
  }
  
  .welcome-stats {
    gap: 15px;
  }
  
  .stat-item {
    min-width: 100%;
  }
  
  .teacher-info-card {
    flex-direction: column;
    text-align: center;
  }
  
  .teacher-avatar {
    margin-bottom: 10px;
  }
  
  .attendance-header {
    flex-direction: column;
    gap: 15px;
  }
  
  .classes-table {
    overflow-x: auto;
    display: block;
  }
  
  .timeline-item {
    flex-wrap: wrap;
  }
  
  .timeline-hours {
    margin-left: 0;
    width: 100%;
    text-align: center;
  }
}

@media (max-width: 480px) {
  .welcome-card {
    padding: 20px;
  }
  
  .welcome-content h1 {
    font-size: 20px;
  }
  
  .stat-info h3 {
    font-size: 20px;
  }
  
  .card-numbers .main-count {
    font-size: 28px;
  }
  
  .schedule-card {
    padding: 15px;
  }
}

/* ==============================
   ANIMATIONS
   ============================== */
@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(30px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.welcome-card, .teacher-info-card, .attendance-card, 
.stat-card, .schedule-section, .classes-section, .weekly-attendance {
  animation: fadeInUp 0.6s ease forwards;
  opacity: 0;
}

.stat-card:nth-child(1) { animation-delay: 0.1s; }
.stat-card:nth-child(2) { animation-delay: 0.2s; }
.stat-card:nth-child(3) { animation-delay: 0.3s; }
.stat-card:nth-child(4) { animation-delay: 0.4s; }

/* ==============================
   CUSTOM SCROLLBAR
   ============================== */
.dashboard-wrapper::-webkit-scrollbar {
  width: 8px;
}

.dashboard-wrapper::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 10px;
}

.dashboard-wrapper::-webkit-scrollbar-thumb {
  background: linear-gradient(135deg, #2196F3, #1976D2);
  border-radius: 10px;
}

.dashboard-wrapper::-webkit-scrollbar-thumb:hover {
  background: linear-gradient(135deg, #1976D2, #0d47a1);
}
</style>
</head>
<body>

<!-- ✅ DASHBOARD CONTENT -->
<div class="dashboard-wrapper">
  <!-- Welcome Section -->
  <div class="welcome-section">
    <div class="welcome-card">
      <div class="welcome-content">
        <h1>
          <i class="fa-solid fa-chalkboard-user"></i>
          Welcome, <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <p>Teacher Dashboard • <?= date("l, d M Y") ?></p>
      </div>
    </div>
  </div>

  <?php if ($teacher_info): ?>


  <!-- Today's Attendance Card -->
  <div class="attendance-card">
    <div class="attendance-header">
      <h3><i class="fa-solid fa-calendar-check"></i> Today's Attendance</h3>
      <?php if ($today_attendance): ?>
        <div class="attendance-badge">
          <i class="fa-solid fa-check-circle"></i> Checked In
        </div>
      <?php else: ?>
        <div class="attendance-badge warning">
          <i class="fa-solid fa-clock"></i> Not Checked In
        </div>
      <?php endif; ?>
    </div>
    
    <?php if ($today_attendance): ?>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
      <div>
        <div style="font-size: 14px; color: #666; margin-bottom: 5px;">Check In Time</div>
        <div style="font-size: 20px; font-weight: 700; color: #333;">
          <?= date('h:i A', strtotime($today_attendance['time_in'])) ?>
        </div>
      </div>
      <?php if ($today_attendance['time_out']): ?>
      <div>
        <div style="font-size: 14px; color: #666; margin-bottom: 5px;">Check Out Time</div>
        <div style="font-size: 20px; font-weight: 700; color: #333;">
          <?= date('h:i A', strtotime($today_attendance['time_out'])) ?>
        </div>
      </div>
      <?php endif; ?>
      <div>
        <div style="font-size: 14px; color: #666; margin-bottom: 5px;">Hours Worked</div>
        <div style="font-size: 20px; font-weight: 700; color: #333;">
          <?= $today_attendance['minutes_worked'] ? round($today_attendance['minutes_worked'] / 60, 1) . ' hours' : '--' ?>
        </div>
      </div>
    </div>
    <?php else: ?>
    <div style="text-align: center; padding: 20px;">
      <i class="fa-solid fa-clock" style="font-size: 48px; color: #ccc; margin-bottom: 15px;"></i>
      <p style="color: #666; margin-bottom: 20px;">You haven't checked in today. Click below to mark your attendance.</p>
    </div>
    <?php endif; ?>
    
    <?php if ($today_attendance && !$today_attendance['time_out']): ?>
    <div style="margin-top: 20px; text-align: right;">
      <button class="btn-attendance outline" onclick="checkOut()">
        <i class="fa-solid fa-sign-out-alt"></i> Check Out
      </button>
    </div>
    <?php endif; ?>
  </div>

  <!-- Stats Cards -->
  <div class="stats-grid">
    <div class="stat-card classes">
      <div class="card-header">
        <div class="card-title">
          <h3>My Classes</h3>
        </div>
        <div class="card-icon">
          <i class="fa-solid fa-chalkboard"></i>
        </div>
      </div>
      <div class="card-numbers">
        <div class="main-count"><?= count($teacher_classes) ?></div>
      </div>
    </div>

    <div class="stat-card students">
      <div class="card-header">
        <div class="card-title">
          <h3>Total Students</h3>
        </div>
        <div class="card-icon">
          <i class="fa-solid fa-users"></i>
        </div>
      </div>
      <div class="card-numbers">
        <div class="main-count"><?= $total_students ?></div>
      </div>
    </div>

    <div class="stat-card subjects">
      <div class="card-header">
        <div class="card-title">
          <h3>Subjects</h3>
        </div>
        <div class="card-icon">
          <i class="fa-solid fa-book"></i>
        </div>
      </div>
      <div class="card-numbers">
        <div class="main-count"><?= count($teacher_classes) ?></div>
      </div>
    </div>

    <div class="stat-card attendance">
      <div class="card-header">
        <div class="card-title">
          <h3>Attendance Rate</h3>
        </div>
        <div class="card-icon">
          <i class="fa-solid fa-chart-line"></i>
        </div>
      </div>
      <div class="card-numbers">
        <div class="main-count">
          <?php 
          $total_days = count($weekly_attendance);
          $present_days = 0;
          foreach ($weekly_attendance as $day) {
              if ($day['time_in']) $present_days++;
          }
          echo $total_days > 0 ? round(($present_days / $total_days) * 100) . '%' : '0%';
          ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Weekly Schedule -->
  <?php if (!empty($teacher_schedule)): ?>
  <div class="schedule-section">
    <div class="schedule-header">
      <h3><i class="fa-solid fa-calendar-week"></i> This Week's Schedule</h3>
      <span class="teacher-badge" style="background: #4CAF50;">
        <i class="fa-solid fa-clock"></i> Active Schedule
      </span>
    </div>
    <div class="schedule-grid">
      <?php 
      $days = ['Sun' => 'Sunday', 'Mon' => 'Monday', 'Tue' => 'Tuesday', 
               'Wed' => 'Wednesday', 'Thu' => 'Thursday', 'Fri' => 'Friday', 'Sat' => 'Saturday'];
      $current_day = date('D');
      foreach ($teacher_schedule as $schedule): 
      ?>
      <div class="schedule-card" style="<?= $schedule['day_of_week'] === $current_day ? 'border-left-color: #4CAF50; background: #e8f5e9;' : '' ?>">
        <div class="schedule-day">
          <i class="fa-regular fa-calendar"></i> <?= $days[$schedule['day_of_week']] ?? $schedule['day_of_week'] ?>
          <?php if ($schedule['day_of_week'] === $current_day): ?>
            <span style="background: #4CAF50; color: white; padding: 3px 8px; border-radius: 12px; font-size: 11px;">Today</span>
          <?php endif; ?>
        </div>
        <div class="schedule-time">
          <i class="fa-regular fa-clock"></i> 
          <?= date('h:i A', strtotime($schedule['start_time'])) ?> - 
          <?= date('h:i A', strtotime($schedule['end_time'])) ?>
        </div>
        <div class="schedule-subject"><?= htmlspecialchars($schedule['subject_name']) ?></div>
        <div class="schedule-class">
          <i class="fa-solid fa-users"></i> <?= htmlspecialchars($schedule['class_name']) ?>
        </div>
        <?php if (!empty($schedule['room_name'])): ?>
        <div class="schedule-room">
          <i class="fa-solid fa-door-open"></i> <?= htmlspecialchars($schedule['room_name']) ?>
          <?php if (!empty($schedule['building_name'])): ?>
            (<?= htmlspecialchars($schedule['building_name']) ?>)
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- My Classes -->
  <?php if (!empty($teacher_classes)): ?>
  <div class="classes-section">
    <div class="classes-header">
      <h3><i class="fa-solid fa-chalkboard-user"></i> My Classes & Subjects</h3>
      <span class="teacher-badge" style="background: #FF9800;">
        <i class="fa-solid fa-users"></i> <?= $total_students ?> Total Students
      </span>
    </div>
    <table class="classes-table">
      <thead>
        <tr>
          <th>Subject</th>
          <th>Class</th>
          <th>Students</th>
          <th>Sessions/Week</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($teacher_classes as $class): ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars($class['subject_name']) ?></strong>
            <div style="font-size: 12px; color: #999;"><?= htmlspecialchars($class['subject_code']) ?></div>
          </td>
          <td><?= htmlspecialchars($class['class_name']) ?></td>
          <td>
            <span class="class-badge">
              <i class="fa-solid fa-user-graduate"></i> <?= $class['student_count'] ?>
            </span>
          </td>
          <td>
            <span class="class-badge" style="background: #e3f2fd; color: #1976D2;">
              <i class="fa-regular fa-clock"></i> <?= $class['sessions_per_week'] ?> sessions
            </span>
          </td>
          <td>
            <span style="color: #4CAF50;">
              <i class="fa-solid fa-circle-check"></i> Active
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Weekly Attendance History -->
  <?php if (!empty($weekly_attendance)): ?>
  <div class="weekly-attendance">
    <div class="weekly-header">
      <h3><i class="fa-solid fa-clock-rotate-left"></i> Last 7 Days Attendance</h3>
      <span class="teacher-badge" style="background: #9C27B0;">
        <i class="fa-solid fa-chart-simple"></i> Attendance History
      </span>
    </div>
    <div class="attendance-timeline">
      <?php foreach ($weekly_attendance as $day): ?>
      <div class="timeline-item">
        <div class="timeline-date">
          <?= date('M d', strtotime($day['date'])) ?>
          <div class="timeline-day"><?= $day['day_name'] ?></div>
        </div>
        <div class="timeline-status <?= $day['time_in'] ? 'status-present' : 'status-absent' ?>">
          <?php if ($day['time_in']): ?>
            <i class="fa-solid fa-check-circle"></i> Present
          <?php else: ?>
            <i class="fa-solid fa-times-circle"></i> Absent
          <?php endif; ?>
        </div>
        <?php if ($day['time_in']): ?>
        <div class="timeline-time">
          <i class="fa-regular fa-clock"></i> 
          In: <?= date('h:i A', strtotime($day['time_in'])) ?>
          <?php if ($day['time_out']): ?>
            | Out: <?= date('h:i A', strtotime($day['time_out'])) ?>
          <?php endif; ?>
        </div>
        <?php if ($day['minutes_worked']): ?>
        <div class="timeline-hours">
          <i class="fa-regular fa-hourglass"></i> <?= round($day['minutes_worked'] / 60, 1) ?> hrs
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="timeline-time" style="color: #999;">
          <i class="fa-regular fa-calendar-xmark"></i> No record
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php else: ?>
  <!-- No teacher data -->
  <div class="attendance-card" style="text-align: center; padding: 60px;">
    <i class="fa-solid fa-user-tie" style="font-size: 64px; color: #ccc; margin-bottom: 20px;"></i>
    <h3 style="color: #333; margin-bottom: 10px;">No Teacher Data Found</h3>
    <p style="color: #666;">Unable to load teacher information. Please contact the administrator.</p>
  </div>
  <?php endif; ?>
</div>

<!-- ✅ FOOTER -->
<?php include('../includes/footer.php'); ?>

<script>
// Check In Function
function checkIn() {
  if (confirm('Are you sure you want to check in now?')) {
    // Send AJAX request to check in
    fetch('teacher_attendance.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        action: 'check_in',
        teacher_id: <?= json_encode($teacher_id) ?>
      })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showNotification('Successfully checked in!', 'success');
        setTimeout(() => location.reload(), 1500);
      } else {
        showNotification(data.message || 'Failed to check in', 'error');
      }
    })
    .catch(error => {
      console.error('Error:', error);
      showNotification('An error occurred', 'error');
    });
  }
}

// Check Out Function
function checkOut() {
  if (confirm('Are you sure you want to check out now?')) {
    // Send AJAX request to check out
    fetch('teacher_attendance.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        action: 'check_out',
        teacher_id: <?= json_encode($teacher_id) ?>
      })
    })
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        showNotification('Successfully checked out!', 'success');
        setTimeout(() => location.reload(), 1500);
      } else {
        showNotification(data.message || 'Failed to check out', 'error');
      }
    })
    .catch(error => {
      console.error('Error:', error);
      showNotification('An error occurred', 'error');
    });
  }
}

// Show Notification
function showNotification(message, type = 'success') {
  const notification = document.createElement('div');
  notification.style.cssText = `
    position: fixed;
    top: 100px;
    right: 20px;
    background: ${type === 'success' ? 'linear-gradient(135deg, #4CAF50, #45a049)' : 'linear-gradient(135deg, #f44336, #d32f2f)'};
    color: white;
    padding: 15px 25px;
    border-radius: 10px;
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    z-index: 1000;
    font-size: 14px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: slideIn 0.3s ease;
  `;
  notification.innerHTML = `
    <i class="fa-solid ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
    ${message}
  `;
  document.body.appendChild(notification);
  
  setTimeout(() => {
    notification.style.animation = 'slideOut 0.3s ease';
    setTimeout(() => notification.remove(), 300);
  }, 3000);
}

// Intersection Observer for animations
const observer = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      entry.target.style.opacity = '1';
      entry.target.style.transform = 'translateY(0)';
    }
  });
}, { threshold: 0.1 });

// Observe all cards
document.querySelectorAll('.stat-card, .schedule-card, .timeline-item, .teacher-info-card, .attendance-card').forEach(card => {
  card.style.opacity = '0';
  card.style.transform = 'translateY(30px)';
  card.style.transition = 'all 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
  observer.observe(card);
});

// Add CSS for animations
const style = document.createElement('style');
style.textContent = `
  @keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
  }
  @keyframes slideOut {
    from { transform: translateX(0); opacity: 1; }
    to { transform: translateX(100%); opacity: 0; }
  }
`;
document.head.appendChild(style);
</script>
</body>
</html>