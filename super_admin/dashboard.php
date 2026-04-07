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

// ✅ Count Functions
function getCounts($pdo, $table) {
    try {
        $active = $pdo->query("SELECT COUNT(*) FROM $table WHERE status='active'")->fetchColumn();
        $inactive = $pdo->query("SELECT COUNT(*) FROM $table WHERE status='inactive'")->fetchColumn();
        return [$active ?: 0, $inactive ?: 0];
    } catch (Exception $e) {
        return [0, 0];
    }
}

function getTotalCount($pdo, $table) {
    try {
        return $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() ?: 0;
    } catch (Exception $e) {
        return 0;
    }
}

function getRoomCounts($pdo) {
    try {
        $available = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='available'")->fetchColumn();
        $maintenance = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='maintenance'")->fetchColumn();
        $inactive = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='inactive'")->fetchColumn();

        return [
            'available' => $available ?: 0,
            'maintenance' => $maintenance ?: 0,
            'inactive' => $inactive ?: 0
        ];
    } catch (Exception $e) {
        return [0, 0, 0];
    }
}

// ✅ Get count for faculty_campus (junction table)
function getFacultyCampusCount($pdo) {
    try {
        // Since it's a junction table, we'll count all relationships
        $total = $pdo->query("SELECT COUNT(*) FROM faculty_campus")->fetchColumn();
        // Junction tables typically don't have status, so we'll return total as "active" and 0 as "inactive"
        return [$total ?: 0, 0];
    } catch (Exception $e) {
        return [0, 0];
    }
}

// ✅ Get all stats - CORRECTED: Using 'subjects' table instead of 'courses'
$stats = [
    'campus' => getCounts($pdo, 'campus'),
    'faculties' => getCounts($pdo, 'faculties'),
    'departments' => getCounts($pdo, 'departments'),
    'programs' => getCounts($pdo, 'programs'),
    'teachers' => getCounts($pdo, 'teachers'),
    'students' => getCounts($pdo, 'students'),
    'parents' => getCounts($pdo, 'parents'),
    'announcements' => getCounts($pdo, 'announcement'),
    'classes' => getCounts($pdo, 'classes'),
    'rooms' => [
        getRoomCounts($pdo)['available'],   // Active-like
        getRoomCounts($pdo)['inactive']     // Inactive
    ],
    'courses' => getCounts($pdo, 'subject'), // CORRECTED: Changed from 'courses' to 'subjects'
    'faculty_campus' => getFacultyCampusCount($pdo) // NEW: Faculty-Campus relationships
];

// Calculate totals for welcome banner
$total_students = $stats['students'][0] + $stats['students'][1];
$total_teachers = $stats['teachers'][0] + $stats['teachers'][1];
$total_courses = $stats['courses'][0] + $stats['courses'][1]; // Now correctly counts subjects
$total_faculty_campus = $stats['faculty_campus'][0]; // Total relationships

// ✅ Get recent activity
$recent_activities = [];
try {
    $activityStmt = $pdo->query("
        SELECT 'user' as type, username as title, 'New user registered' as description, created_at as date
        FROM users 
        ORDER BY created_at DESC 
        LIMIT 5
        UNION
        SELECT 'announcement' as type, title, 'New announcement posted' as description, created_at as date
        FROM announcement 
        ORDER BY created_at DESC 
        LIMIT 5
        ORDER BY date DESC 
        LIMIT 8
    ");
    $recent_activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_activities = [];
}

// ✅ Get monthly enrollment data
$monthly_data = [];
try {
    $monthlyStmt = $pdo->query("
        SELECT 
            DATE_FORMAT(created_at, '%b') as month,
            COUNT(*) as students,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active
        FROM students 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b')
        ORDER BY DATE_FORMAT(created_at, '%Y-%m')
    ");
    $monthly_data = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $monthly_data = [];
}

// ✅ Get gender distribution
$gender_data = [];
try {
    $genderStmt = $pdo->query("
        SELECT 
            CASE 
                WHEN gender IN ('Male', 'male', 'M') THEN 'Male'
                WHEN gender IN ('Female', 'female', 'F') THEN 'Female'
                ELSE 'Other' 
            END as gender,
            COUNT(*) as count
        FROM students 
        WHERE gender IS NOT NULL 
        GROUP BY 
            CASE 
                WHEN gender IN ('Male', 'male', 'M') THEN 'Male'
                WHEN gender IN ('Female', 'female', 'F') THEN 'Female'
                ELSE 'Other' 
            END
    ");
    $gender_data = $genderStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $gender_data = [];
}

// ✅ Get department-wise student count
$department_students = [];
try {
    $deptStmt = $pdo->query("
        SELECT 
            d.department_name as name,
            COUNT(s.student_id) as count
        FROM departments d
        LEFT JOIN students s ON d.department_id = s.department_id
        GROUP BY d.department_id, d.department_name
        ORDER BY count DESC
        LIMIT 8
    ");
    $department_students = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $department_students = [];
}

// ✅ Get program enrollment data
$program_enrollment = [];
try {
    $programStmt = $pdo->query("
        SELECT 
            p.program_name as name,
            COUNT(s.student_id) as students,
            SUM(CASE WHEN s.status = 'active' THEN 1 ELSE 0 END) as active
        FROM programs p
        LEFT JOIN students s ON p.program_id = s.program_id
        GROUP BY p.program_id, p.program_name
        ORDER BY students DESC
        LIMIT 6
    ");
    $program_enrollment = $programStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $program_enrollment = [];
}

// ✅ Get teacher qualification data
$teacher_qualification = [];
try {
    $qualStmt = $pdo->query("
        SELECT 
            CASE 
                WHEN qualification IS NULL OR qualification = '' THEN 'Not Specified'
                ELSE qualification 
            END as qualification,
            COUNT(*) as count
        FROM teachers 
        GROUP BY 
            CASE 
                WHEN qualification IS NULL OR qualification = '' THEN 'Not Specified'
                ELSE qualification 
            END
        ORDER BY count DESC
        LIMIT 6
    ");
    $teacher_qualification = $qualStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $teacher_qualification = [];
}

// ✅ Get room utilization data
$room_utilization = [];
try {
    $roomStmt = $pdo->query("
        SELECT 
            status,
            COUNT(*) as count,
            ROUND((COUNT(*) * 100.0 / (SELECT COUNT(*) FROM rooms)), 1) as percentage
        FROM rooms 
        GROUP BY status
        ORDER BY 
            CASE status
                WHEN 'available' THEN 1
                WHEN 'occupied' THEN 2
                WHEN 'maintenance' THEN 3
                WHEN 'inactive' THEN 4
                ELSE 5
            END
    ");
    $room_utilization = $roomStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $room_utilization = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard | Hormuud University</title>
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
  background: linear-gradient(135deg, #0072CE 0%, #005fa3 100%);
  color: white;
  border-radius: 20px;
  padding: 35px 40px;
  box-shadow: 
    0 20px 40px rgba(0, 114, 206, 0.2),
    0 10px 20px rgba(0, 114, 206, 0.1),
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
   STATS CARDS GRID
   ============================== */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
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

.stat-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(90deg, var(--card-color), color-mix(in srgb, var(--card-color) 30%, white));
}

.stat-card::after {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: linear-gradient(135deg, transparent 30%, rgba(255, 255, 255, 0.3) 100%);
  opacity: 0;
  transition: opacity 0.3s ease;
}

.stat-card:hover::after {
  opacity: 1;
}

.stat-card.campus { --card-color: #0072CE; }
.stat-card.faculty { --card-color: #00843D; }
.stat-card.department { --card-color: #00A651; }
.stat-card.program { --card-color: #FFB400; }
.stat-card.section { --card-color: #9C27B0; }
.stat-card.teacher { --card-color: #2196F3; }
.stat-card.student { --card-color: #4CAF50; }
.stat-card.parent { --card-color: #FF9800; }
.stat-card.announcement { --card-color: #E91E63; }
.stat-card.class { --card-color: #3F51B5; }
.stat-card.room { --card-color: #00BCD4; }
.stat-card.course { --card-color: #8BC34A; }
.stat-card.faculty-campus { --card-color: #9C27B0; } /* NEW: Faculty-Campus color */

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

.card-title p {
  font-size: 12px;
  color: #999;
  margin: 0;
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
  position: relative;
  overflow: hidden;
}

.card-icon::before {
  content: '';
  position: absolute;
  top: -10px;
  left: -10px;
  right: -10px;
  bottom: -10px;
  background: linear-gradient(45deg, transparent 30%, rgba(255, 255, 255, 0.3) 100%);
  transform: rotate(45deg);
}

.card-content {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  position: relative;
  z-index: 2;
}

.card-numbers .main-count {
  font-size: 36px;
  font-weight: 800;
  color: var(--card-color);
  line-height: 1;
  margin-bottom: 5px;
  text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.card-numbers .sub-count {
  font-size: 14px;
  color: #777;
  display: flex;
  gap: 15px;
}

.card-numbers .sub-count span {
  display: flex;
  align-items: center;
  gap: 5px;
}

.card-progress {
  text-align: right;
}

.progress-percent {
  font-size: 20px;
  font-weight: 700;
  color: var(--card-color);
  margin-bottom: 5px;
}

.progress-label {
  font-size: 12px;
  color: #999;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

/* ==============================
   CHARTS SECTION - 6 CHARTS LAYOUT
   ============================== */
.charts-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 25px;
  margin-bottom: 40px;
}

.chart-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 25px;
  margin-bottom: 25px;
}

.chart-card {
  background: white;
  border-radius: 20px;
  padding: 25px;
  box-shadow: 
    0 10px 30px rgba(0, 0, 0, 0.08),
    0 4px 12px rgba(0, 0, 0, 0.04);
  transition: all 0.3s ease;
  position: relative;
  overflow: hidden;
}

.chart-card:hover {
  transform: translateY(-5px);
  box-shadow: 
    0 20px 40px rgba(0, 0, 0, 0.12),
    0 8px 24px rgba(0, 0, 0, 0.08);
}

.chart-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(90deg, #0072CE, #00A651);
}

.chart-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.chart-header h3 {
  font-size: 18px;
  font-weight: 700;
  color: #333;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 10px;
}

.chart-header h3 i {
  color: #0072CE;
  background: linear-gradient(135deg, #0072CE, #00A651);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}

.chart-actions {
  display: flex;
  gap: 8px;
}

.chart-btn {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid #e0e0e0;
  background: white;
  color: #666;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.2s ease;
  font-size: 14px;
}

.chart-btn:hover {
  background: linear-gradient(135deg, #0072CE, #00A651);
  color: white;
  border-color: transparent;
  transform: scale(1.1);
  box-shadow: 0 5px 15px rgba(0, 114, 206, 0.2);
}

.chart-container {
  position: relative;
  height: 250px;
  width: 100%;
}

/* ==============================
   ACTIVITY FEED
   ============================== */
.activity-card {
  background: white;
  border-radius: 20px;
  padding: 30px;
  box-shadow: 
    0 10px 30px rgba(0, 0, 0, 0.08),
    0 4px 12px rgba(0, 0, 0, 0.04);
  margin-bottom: 40px;
  position: relative;
  overflow: hidden;
}

.activity-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(90deg, #FFB400, #FF9800);
}

.activity-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 25px;
}

.activity-header h3 {
  font-size: 20px;
  font-weight: 700;
  color: #333;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 10px;
}

.activity-header h3 i {
  color: #FFB400;
  background: linear-gradient(135deg, #FFB400, #FF9800);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
}

.activity-list {
  display: flex;
  flex-direction: column;
  gap: 15px;
}

.activity-item {
  display: flex;
  align-items: flex-start;
  gap: 15px;
  padding: 20px;
  border-radius: 15px;
  transition: all 0.3s ease;
  background: #f8fafc;
  border: 1px solid #eef2f7;
  position: relative;
  overflow: hidden;
}

.activity-item:hover {
  background: white;
  transform: translateX(5px);
  box-shadow: 
    0 5px 20px rgba(0, 0, 0, 0.08),
    0 3px 10px rgba(0, 0, 0, 0.04);
  border-color: transparent;
}

.activity-item::before {
  content: '';
  position: absolute;
  left: 0;
  top: 0;
  bottom: 0;
  width: 4px;
  background: linear-gradient(180deg, var(--activity-color), color-mix(in srgb, var(--activity-color) 30%, white));
}

.activity-item.user { --activity-color: #0072CE; }
.activity-item.announcement { --activity-color: #FFB400; }

.activity-icon {
  width: 45px;
  height: 45px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  color: white;
  flex-shrink: 0;
  position: relative;
  z-index: 2;
}

.activity-icon.user { 
  background: linear-gradient(135deg, #0072CE, #00A0FF);
  box-shadow: 0 5px 15px rgba(0, 114, 206, 0.2);
}
.activity-icon.announcement { 
  background: linear-gradient(135deg, #FFB400, #FF9800);
  box-shadow: 0 5px 15px rgba(255, 180, 0, 0.2);
}

.activity-content {
  flex: 1;
}

.activity-title {
  font-weight: 600;
  color: #333;
  margin-bottom: 5px;
  font-size: 16px;
}

.activity-desc {
  font-size: 14px;
  color: #666;
  margin-bottom: 8px;
  line-height: 1.4;
}

.activity-time {
  font-size: 12px;
  color: #999;
  display: flex;
  align-items: center;
  gap: 5px;
}

/* ==============================
   RESPONSIVE DESIGN
   ============================== */
@media (max-width: 1200px) {
  .stats-grid {
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  }
  
  .chart-row {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 992px) {
  .chart-row {
    grid-template-columns: 1fr;
  }
  
  .charts-grid {
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
    flex-direction: column;
    text-align: center;
    gap: 10px;
    padding: 15px;
  }
  
  .stat-icon {
    width: 50px;
    height: 50px;
    font-size: 20px;
  }
  
  .stats-grid {
    grid-template-columns: 1fr;
  }
  
  .chart-card {
    padding: 20px;
  }
  
  .chart-container {
    height: 220px;
  }
}

@media (max-width: 480px) {
  .welcome-card {
    padding: 20px;
  }
  
  .welcome-content h1 {
    font-size: 20px;
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
  
  .welcome-content p {
    font-size: 14px;
  }
  
  .stat-info h3 {
    font-size: 20px;
  }
  
  .card-numbers .main-count {
    font-size: 28px;
  }
  
  .activity-item {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  
  .chart-header h3 {
    font-size: 16px;
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

.welcome-card, .stat-card, .chart-card, .activity-card {
  animation: fadeInUp 0.6s ease forwards;
  opacity: 0;
}

.stat-card:nth-child(1) { animation-delay: 0.1s; }
.stat-card:nth-child(2) { animation-delay: 0.2s; }
.stat-card:nth-child(3) { animation-delay: 0.3s; }
.stat-card:nth-child(4) { animation-delay: 0.4s; }
.stat-card:nth-child(5) { animation-delay: 0.5s; }
.stat-card:nth-child(6) { animation-delay: 0.6s; }
.stat-card:nth-child(7) { animation-delay: 0.7s; }
.stat-card:nth-child(8) { animation-delay: 0.8s; }
.stat-card:nth-child(9) { animation-delay: 0.9s; }
.stat-card:nth-child(10) { animation-delay: 1s; }
.stat-card:nth-child(11) { animation-delay: 1.1s; }
.stat-card:nth-child(12) { animation-delay: 1.2s; }
.stat-card:nth-child(13) { animation-delay: 1.3s; } /* NEW: For faculty-campus card */

.chart-card:nth-child(1) { animation-delay: 0.3s; }
.chart-card:nth-child(2) { animation-delay: 0.4s; }
.chart-card:nth-child(3) { animation-delay: 0.5s; }
.chart-card:nth-child(4) { animation-delay: 0.6s; }
.chart-card:nth-child(5) { animation-delay: 0.7s; }
.chart-card:nth-child(6) { animation-delay: 0.8s; }

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
  background: linear-gradient(135deg, #0072CE, #00A651);
  border-radius: 10px;
}

.dashboard-wrapper::-webkit-scrollbar-thumb:hover {
  background: linear-gradient(135deg, #005fa3, #00843D);
}

/* ==============================
   GLOW EFFECTS
   ============================== */
.glow {
  position: relative;
}

.glow::before {
  content: '';
  position: absolute;
  top: -2px;
  left: -2px;
  right: -2px;
  bottom: -2px;
  background: linear-gradient(45deg, 
    var(--card-color),
    color-mix(in srgb, var(--card-color) 50%, white),
    var(--card-color)
  );
  border-radius: inherit;
  z-index: -1;
  filter: blur(10px);
  opacity: 0;
  transition: opacity 0.3s ease;
}

.glow:hover::before {
  opacity: 0.3;
}

/* ==============================
   PRINT STYLES
   ============================== */
@media print {
  .welcome-card {
    background: #fff !important;
    color: #000 !important;
    box-shadow: none !important;
    border: 1px solid #ddd !important;
  }
  
  .stat-card, .chart-card, .activity-card {
    break-inside: avoid;
    box-shadow: none !important;
    border: 1px solid #ddd !important;
    transform: none !important;
  }
  
  .chart-btn, .activity-header .btn {
    display: none !important;
  }
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
          <i class="fa-solid fa-graduation-cap"></i>
          Welcome, <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
        </h1>
        <p>Role: <strong><?= ucfirst(str_replace('_', ' ', $role)) ?></strong> • Date: <?= date("d M, Y") ?></p>
        <p>University Management Dashboard</p>
        
      </div>
    </div>
  </div>

  <!-- Stats Cards Grid -->
  <div class="stats-grid">
    <?php
    $cardData = [
        'campus' => ['icon' => 'fa-building-columns', 'title' => 'Campuses', 'data' => $stats['campus']],
        'faculty' => ['icon' => 'fa-graduation-cap', 'title' => 'Faculties', 'data' => $stats['faculties']],
        'department' => ['icon' => 'fa-building', 'title' => 'Departments', 'data' => $stats['departments']],
        'program' => ['icon' => 'fa-layer-group', 'title' => 'Programs', 'data' => $stats['programs']],
        'teacher' => ['icon' => 'fa-user-tie', 'title' => 'Teachers', 'data' => $stats['teachers']],
        'student' => ['icon' => 'fa-user-graduate', 'title' => 'Students', 'data' => $stats['students']],
        'parent' => ['icon' => 'fa-user-group', 'title' => 'Parents', 'data' => $stats['parents']],
        'announcement' => ['icon' => 'fa-bullhorn', 'title' => 'Announcements', 'data' => $stats['announcements']],
        'class' => ['icon' => 'fa-chalkboard', 'title' => 'Classes', 'data' => $stats['classes']],
        'room' => ['icon' => 'fa-door-open', 'title' => 'Rooms', 'data' => $stats['rooms']],
        'course' => ['icon' => 'fa-book', 'title' => 'Courses', 'data' => $stats['courses']],
        'faculty-campus' => ['icon' => 'fa-sitemap', 'title' => 'Faculty-Campus Links', 'data' => $stats['faculty_campus']], // NEW: Faculty-Campus card
    ];
    
    foreach ($cardData as $key => $card):
        $active = $card['data'][0];
        $inactive = $card['data'][1];
        $total = $active + $inactive;
        // For faculty_campus (junction table), show 100% active since all relationships are considered active
        $percentage = $key === 'faculty-campus' ? 100 : ($total > 0 ? round(($active / $total) * 100) : 0);
    ?>
    <div class="stat-card glow <?= $key ?>">
      <div class="card-header">
        <div class="card-title">
          <h3><?= $card['title'] ?></h3>
          <p>University Management</p>
        </div>
        <div class="card-icon">
          <i class="fa-solid <?= $card['icon'] ?>"></i>
        </div>
      </div>
      <div class="card-content">
        <div class="card-numbers">
          <div class="main-count" data-target="<?= $active ?>">0</div>
          <div class="sub-count">
            <span><i class="fa-solid fa-circle-check" style="color: #4CAF50;"></i> Total: <?= $total ?></span>
            <span><i class="fa-solid fa-circle-pause" style="color: #FF9800;"></i> Inactive: <?= $inactive ?></span>
          </div>
        </div>
        <div class="card-progress">
          <div class="progress-percent"><?= $percentage ?>%</div>
          <div class="progress-label">Active Rate</div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Charts Section - 6 CHARTS -->
  <div class="charts-section">
    <!-- Row 1: 3 Charts -->
    <div class="chart-row">
      <!-- Chart 1: Active vs Inactive Overview -->
      <div class="chart-card">
        <div class="chart-header">
          <h3><i class="fa-solid fa-chart-column"></i> Active vs Inactive</h3>
          <div class="chart-actions">
            <button class="chart-btn" title="Refresh"><i class="fa-solid fa-rotate"></i></button>
            <button class="chart-btn" title="Download"><i class="fa-solid fa-download"></i></button>
          </div>
        </div>
        <div class="chart-container">
          <canvas id="barChart"></canvas>
        </div>
      </div>
      
      <!-- Chart 2: Active Distribution -->
      <div class="chart-card">
        <div class="chart-header">
          <h3><i class="fa-solid fa-chart-pie"></i> Active Distribution</h3>
          <div class="chart-actions">
            <button class="chart-btn" title="Refresh"><i class="fa-solid fa-rotate"></i></button>
            <button class="chart-btn" title="Download"><i class="fa-solid fa-download"></i></button>
          </div>
        </div>
        <div class="chart-container">
          <canvas id="pieChart"></canvas>
        </div>
      </div>
      
      <!-- Chart 3: Growth Trends -->
      <div class="chart-card">
        <div class="chart-header">
          <h3><i class="fa-solid fa-chart-line"></i> Growth Trends</h3>
          <div class="chart-actions">
            <button class="chart-btn" title="Refresh"><i class="fa-solid fa-rotate"></i></button>
            <button class="chart-btn" title="Download"><i class="fa-solid fa-download"></i></button>
          </div>
        </div>
        <div class="chart-container">
          <canvas id="growthChart"></canvas>
        </div>
      </div>
    </div>
    
    <!-- Row 2: 3 Charts -->
    <div class="chart-row">
      <!-- Chart 4: Department Students -->
      <div class="chart-card">
        <div class="chart-header">
          <h3><i class="fa-solid fa-building"></i> Department Students</h3>
          <div class="chart-actions">
            <button class="chart-btn" title="Refresh"><i class="fa-solid fa-rotate"></i></button>
            <button class="chart-btn" title="Download"><i class="fa-solid fa-download"></i></button>
          </div>
        </div>
        <div class="chart-container">
          <canvas id="deptChart"></canvas>
        </div>
      </div>
      
      <!-- Chart 5: Program Enrollment -->
      <div class="chart-card">
        <div class="chart-header">
          <h3><i class="fa-solid fa-graduation-cap"></i> Program Enrollment</h3>
          <div class="chart-actions">
            <button class="chart-btn" title="Refresh"><i class="fa-solid fa-rotate"></i></button>
            <button class="chart-btn" title="Download"><i class="fa-solid fa-download"></i></button>
          </div>
        </div>
        <div class="chart-container">
          <canvas id="programChart"></canvas>
        </div>
      </div>
      
      <!-- Chart 6: Room Utilization -->
      <div class="chart-card">
        <div class="chart-header">
          <h3><i class="fa-solid fa-door-closed"></i> Room Utilization</h3>
          <div class="chart-actions">
            <button class="chart-btn" title="Refresh"><i class="fa-solid fa-rotate"></i></button>
            <button class="chart-btn" title="Download"><i class="fa-solid fa-download"></i></button>
          </div>
        </div>
        <div class="chart-container">
          <canvas id="roomChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Activity Feed -->

</div>

<!-- ✅ FOOTER -->
<?php include('../includes/footer.php'); ?>

<script>
// Chart Colors and Configuration
const chartColors = {
  primary: ['#0072CE', '#00843D', '#00A651', '#FFB400', '#9C27B0', '#2196F3', '#4CAF50', '#FF9800', '#9C27B0', '#E91E63'],
  secondary: ['#E91E63', '#3F51B5', '#00BCD4', '#8BC34A', '#795548', '#607D8B'],
  gradient: {
    blue: ['#0072CE', '#00A0FF'],
    green: ['#00843D', '#00C851'],
    purple: ['#9C27B0', '#E040FB'],
    orange: ['#FFB400', '#FF9800']
  }
};

// Create gradient function
function createGradient(ctx, color1, color2) {
  const gradient = ctx.createLinearGradient(0, 0, 0, 250);
  gradient.addColorStop(0, color1);
  gradient.addColorStop(1, color2);
  return gradient;
}

// Chart Data - Updated to include faculty_campus data
const labels = ['Campuses', 'Faculties', 'Departments', 'Programs', 'Sections', 'Teachers', 'Students', 'Parents', 'Announcements', 'Classes', 'Rooms', 'Courses', 'Faculty-Campus'];
const activeData = [<?= implode(',', array_column($stats, '0')) ?>];
const inactiveData = [<?= implode(',', array_column($stats, '1')) ?>];

// Department Data
const deptLabels = <?= json_encode(array_column($department_students, 'name')) ?>;
const deptCounts = <?= json_encode(array_column($department_students, 'count')) ?>;

// Program Data
const programLabels = <?= json_encode(array_column($program_enrollment, 'name')) ?>;
const programStudents = <?= json_encode(array_column($program_enrollment, 'students')) ?>;
const programActive = <?= json_encode(array_column($program_enrollment, 'active')) ?>;

// Room Utilization Data
const roomLabels = <?= json_encode(array_column($room_utilization, 'status')) ?>;
const roomCounts = <?= json_encode(array_column($room_utilization, 'count')) ?>;
const roomPercentages = <?= json_encode(array_column($room_utilization, 'percentage')) ?>;

// Initialize counters with animation
document.addEventListener('DOMContentLoaded', function() {
  // Counter animation
  const counters = document.querySelectorAll('.main-count');
  counters.forEach(counter => {
    const target = +counter.getAttribute('data-target');
    let current = 0;
    const increment = target / 50;
    const updateCounter = () => {
      if (current < target) {
        current += increment;
        counter.textContent = Math.ceil(current);
        setTimeout(updateCounter, 20);
      } else {
        counter.textContent = target;
      }
    };
    updateCounter();
  });
  
  // Update current year in footer
  const yearSpan = document.querySelector('#currentYear');
  if (yearSpan) {
    yearSpan.textContent = new Date().getFullYear();
  }
});

// 1. Bar Chart - Active vs Inactive (Updated to include new data)
if (document.getElementById('barChart')) {
  const ctx = document.getElementById('barChart').getContext('2d');
  
  const activeGradient = createGradient(ctx, 'rgba(0, 114, 206, 0.8)', 'rgba(0, 114, 206, 0.2)');
  const inactiveGradient = createGradient(ctx, 'rgba(198, 40, 40, 0.8)', 'rgba(198, 40, 40, 0.2)');
  
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Active',
          data: activeData,
          backgroundColor: activeGradient,
          borderRadius: 8,
          borderSkipped: false,
          borderWidth: 0,
          barPercentage: 0.6,
          categoryPercentage: 0.7
        },
        {
          label: 'Inactive',
          data: inactiveData,
          backgroundColor: inactiveGradient,
          borderRadius: 8,
          borderSkipped: false,
          borderWidth: 0,
          barPercentage: 0.6,
          categoryPercentage: 0.7
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'top',
          labels: {
            font: { size: 11 },
            padding: 10,
            usePointStyle: true
          }
        },
        tooltip: {
          mode: 'index',
          intersect: false,
          backgroundColor: 'rgba(255, 255, 255, 0.95)',
          titleColor: '#333',
          bodyColor: '#666',
          borderColor: '#e0e0e0',
          borderWidth: 1,
          padding: 10,
          boxPadding: 4,
          cornerRadius: 6
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: {
            font: { size: 9 },
            maxRotation: 45
          }
        },
        y: {
          beginAtZero: true,
          grid: {
            borderDash: [2, 2],
            drawBorder: false
          },
          ticks: {
            stepSize: 5,
            font: { size: 10 }
          }
        }
      },
      animation: {
        duration: 800,
        easing: 'easeOutQuart'
      }
    }
  });
}

// 2. Pie Chart - Active Distribution (Updated to include new data)
if (document.getElementById('pieChart')) {
  const ctx = document.getElementById('pieChart').getContext('2d');
  
  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: labels,
      datasets: [{
        data: activeData,
        backgroundColor: chartColors.primary,
        borderWidth: 1,
        borderColor: '#fff',
        hoverBorderWidth: 2,
        hoverBorderColor: '#fff',
        hoverOffset: 15
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '60%',
      radius: '80%',
      plugins: {
        legend: {
          position: 'right',
          labels: {
            boxWidth: 12,
            padding: 15,
            font: { size: 9 },
            color: '#555',
            usePointStyle: true
          }
        },
        tooltip: {
          backgroundColor: 'rgba(255, 255, 255, 0.95)',
          borderColor: '#e0e0e0',
          borderWidth: 1,
          padding: 10,
          cornerRadius: 6,
          callbacks: {
            label: function(context) {
              const total = context.dataset.data.reduce((a, b) => a + b, 0);
              const percentage = Math.round((context.parsed / total) * 100);
              return `${context.label}: ${context.parsed} (${percentage}%)`;
            }
          }
        }
      },
      animation: {
        animateScale: true,
        animateRotate: true,
        duration: 1000
      }
    }
  });
}

// 3. Line Chart - Growth Trends
if (document.getElementById('growthChart')) {
  const ctx = document.getElementById('growthChart').getContext('2d');
  
  // Generate sample growth data
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const studentData = [320, 450, 520, 580, 620, 680, 720, 780, 820, 850, 880, 900];
  const teacherData = [45, 48, 52, 55, 58, 60, 62, 65, 68, 70, 72, 75];
  
  const studentGradient = createGradient(ctx, 'rgba(0, 114, 206, 0.2)', 'rgba(0, 114, 206, 0.05)');
  const teacherGradient = createGradient(ctx, 'rgba(0, 132, 61, 0.2)', 'rgba(0, 132, 61, 0.05)');
  
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: months,
      datasets: [
        {
          label: 'Students',
          data: studentData,
          borderColor: '#0072CE',
          backgroundColor: studentGradient,
          fill: true,
          tension: 0.3,
          borderWidth: 2,
          pointBackgroundColor: '#0072CE',
          pointBorderColor: '#fff',
          pointBorderWidth: 1,
          pointRadius: 3,
          pointHoverRadius: 5
        },
        {
          label: 'Teachers',
          data: teacherData,
          borderColor: '#00843D',
          backgroundColor: teacherGradient,
          fill: true,
          tension: 0.3,
          borderWidth: 2,
          borderDash: [3, 3],
          pointBackgroundColor: '#00843D',
          pointBorderColor: '#fff',
          pointBorderWidth: 1,
          pointRadius: 3,
          pointHoverRadius: 5
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: {
        mode: 'index',
        intersect: false,
      },
      plugins: {
        legend: {
          position: 'top',
          labels: {
            font: { size: 11 },
            padding: 10,
            usePointStyle: true
          }
        },
        tooltip: {
          backgroundColor: 'rgba(255, 255, 255, 0.95)',
          borderColor: '#e0e0e0',
          borderWidth: 1,
          padding: 10,
          cornerRadius: 6
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { font: { size: 10 } }
        },
        y: {
          beginAtZero: true,
          grid: {
            borderDash: [2, 2],
            drawBorder: false
          },
          ticks: {
            font: { size: 10 },
            callback: function(value) {
              return value.toLocaleString();
            }
          }
        }
      },
      animation: {
        duration: 1000,
        easing: 'easeOutQuart'
      }
    }
  });
}

// 4. Horizontal Bar Chart - Department Students
if (document.getElementById('deptChart')) {
  const ctx = document.getElementById('deptChart').getContext('2d');
  
  const deptGradient = deptLabels.map((label, index) => {
    const color = chartColors.primary[index % chartColors.primary.length];
    return createGradient(ctx, color + 'CC', color + '66');
  });
  
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: deptLabels,
      datasets: [{
        label: 'Students',
        data: deptCounts,
        backgroundColor: deptGradient,
        borderRadius: 6,
        borderWidth: 0
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: false
        },
        tooltip: {
          backgroundColor: 'rgba(255, 255, 255, 0.95)',
          borderColor: '#e0e0e0',
          borderWidth: 1,
          padding: 10,
          cornerRadius: 6
        }
      },
      scales: {
        x: {
          beginAtZero: true,
          grid: {
            borderDash: [2, 2],
            drawBorder: false
          },
          ticks: {
            font: { size: 10 },
            stepSize: 10
          }
        },
        y: {
          grid: { display: false },
          ticks: {
            font: { size: 10 }
          }
        }
      },
      animation: {
        duration: 800,
        easing: 'easeOutQuart'
      }
    }
  });
}

// 5. Radar Chart - Program Enrollment
if (document.getElementById('programChart')) {
  const ctx = document.getElementById('programChart').getContext('2d');
  
  new Chart(ctx, {
    type: 'radar',
    data: {
      labels: programLabels,
      datasets: [
        {
          label: 'Total Students',
          data: programStudents,
          borderColor: '#0072CE',
          backgroundColor: 'rgba(0, 114, 206, 0.1)',
          borderWidth: 2,
          pointBackgroundColor: '#0072CE',
          pointBorderColor: '#fff',
          pointBorderWidth: 1,
          pointRadius: 3
        },
        {
          label: 'Active Students',
          data: programActive,
          borderColor: '#00A651',
          backgroundColor: 'rgba(0, 166, 81, 0.1)',
          borderWidth: 2,
          borderDash: [3, 3],
          pointBackgroundColor: '#00A651',
          pointBorderColor: '#fff',
          pointBorderWidth: 1,
          pointRadius: 3
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'top',
          labels: {
            font: { size: 11 },
            padding: 10,
            usePointStyle: true
          }
        },
        tooltip: {
          backgroundColor: 'rgba(255, 255, 255, 0.95)',
          borderColor: '#e0e0e0',
          borderWidth: 1,
          padding: 10,
          cornerRadius: 6
        }
      },
      scales: {
        r: {
          beginAtZero: true,
          ticks: {
            font: { size: 9 },
            stepSize: 10
          },
          grid: {
            circular: true
          },
          pointLabels: {
            font: { size: 10 }
          }
        }
      },
      animation: {
        duration: 1000,
        easing: 'easeOutQuart'
      }
    }
  });
}

// 6. Polar Area Chart - Room Utilization
if (document.getElementById('roomChart')) {
  const ctx = document.getElementById('roomChart').getContext('2d');
  
  const roomColors = ['#4CAF50', '#2196F3', '#FF9800', '#9E9E9E'];
  
  new Chart(ctx, {
    type: 'polarArea',
    data: {
      labels: roomLabels,
      datasets: [{
        data: roomCounts,
        backgroundColor: roomColors,
        borderWidth: 1,
        borderColor: '#fff'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'right',
          labels: {
            font: { size: 10 },
            padding: 10,
            boxWidth: 10,
            usePointStyle: true
          }
        },
        tooltip: {
          backgroundColor: 'rgba(255, 255, 255, 0.95)',
          borderColor: '#e0e0e0',
          borderWidth: 1,
          padding: 10,
          cornerRadius: 6,
          callbacks: {
            label: function(context) {
              const percentage = roomPercentages[context.dataIndex];
              return `${context.label}: ${context.raw} (${percentage}%)`;
            }
          }
        }
      },
      scales: {
        r: {
          ticks: {
            display: false
          },
          grid: {
            circular: true
          }
        }
      },
      animation: {
        animateRotate: true,
        animateScale: true,
        duration: 1200
      }
    }
  });
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
document.querySelectorAll('.stat-card, .chart-card, .activity-card').forEach(card => {
  card.style.opacity = '0';
  card.style.transform = 'translateY(30px)';
  card.style.transition = 'all 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
  observer.observe(card);
});

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
  anchor.addEventListener('click', function (e) {
    e.preventDefault();
    const target = document.querySelector(this.getAttribute('href'));
    if (target) {
      target.scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    }
  });
});

// Chart refresh functionality
document.querySelectorAll('.chart-btn .fa-rotate').forEach(btn => {
  btn.closest('.chart-btn').addEventListener('click', function() {
    const chartCard = this.closest('.chart-card');
    const icon = this;
    
    // Add loading animation
    icon.classList.add('fa-spin');
    chartCard.style.opacity = '0.7';
    
    setTimeout(() => {
      icon.classList.remove('fa-spin');
      chartCard.style.opacity = '1';
      
      // Show notification
      const notification = document.createElement('div');
      notification.style.cssText = `
        position: fixed;
        top: 100px;
        right: 20px;
        background: linear-gradient(135deg, #0072CE, #00A651);
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        z-index: 1000;
        font-size: 14px;
        animation: slideIn 0.3s ease;
      `;
      notification.innerHTML = '<i class="fas fa-check-circle"></i> Chart data refreshed!';
      document.body.appendChild(notification);
      
      setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
      }, 2000);
    }, 800);
  });
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