<?php
// dashboard/department_admin.php
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
$department_id = $user['linked_id'] ?? 0; // Get department_id for department admin

// ✅ Verify this is a department admin
if ($role !== 'department_admin') {
    header("Location: ../unauthorized.php");
    exit;
}

// ✅ Get user full name and department details
$first_name = $last_name = $display_name = $department_name = $faculty_name = $campus_name = '';
if ($user_id && $department_id) {
    // Get user details
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
    
    // Get department details with faculty and campus info
    $stmt = $pdo->prepare("
        SELECT 
            d.department_id,
            d.department_name,
            d.department_code,
            d.status as department_status,
            f.faculty_id,
            f.faculty_name,
            c.campus_id,
            c.campus_name
        FROM departments d
        LEFT JOIN faculties f ON d.faculty_id = f.faculty_id
        LEFT JOIN campus c ON d.campus_id = c.campus_id
        WHERE d.department_id = ?
    ");
    $stmt->execute([$department_id]);
    $dept_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dept_data) {
        $department_name = $dept_data['department_name'] ?? 'Unknown Department';
        $department_code = $dept_data['department_code'] ?? '';
        $faculty_name = $dept_data['faculty_name'] ?? 'Unknown Faculty';
        $campus_name = $dept_data['campus_name'] ?? 'Unknown Campus';
    }
}

$name = $display_name ?: ucfirst($user['username'] ?? 'User');

// ✅ Include header
require_once('../includes/header.php');

// ✅ Count Functions for Department Admin (filtered by department_id)
function getDepartmentCounts($pdo, $table, $department_id) {
    try {
        // Check if table has department_id column
        $stmt = $pdo->prepare("SHOW COLUMNS FROM $table LIKE 'department_id'");
        $stmt->execute();
        $has_department_id = $stmt->fetch();
        
        if ($has_department_id) {
            // Table has department_id column
            $active_stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE status='active' AND department_id = ?");
            $active_stmt->execute([$department_id]);
            $active = $active_stmt->fetchColumn();
            
            $inactive_stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE status='inactive' AND department_id = ?");
            $inactive_stmt->execute([$department_id]);
            $inactive = $inactive_stmt->fetchColumn();
            
            return [$active ?: 0, $inactive ?: 0];
        } else {
            // For tables without direct department_id
            return [0, 0];
        }
    } catch (Exception $e) {
        return [0, 0];
    }
}

// ✅ Get department teachers count
function getDepartmentTeachers($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END),0) as active,
                COALESCE(SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END),0) as inactive
            FROM teachers
        ");

        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            (int)$result['active'],
            (int)$result['inactive']
        ];

    } catch (Exception $e) {
        return [0, 0];
    }
}

// ✅ Get department students count
function getDepartmentStudents($pdo, $department_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN s.status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN s.status = 'inactive' THEN 1 ELSE 0 END) as inactive
            FROM students s
            WHERE s.department_id = ?
        ");
        $stmt->execute([$department_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            $result['active'] ?? 0,
            $result['inactive'] ?? 0
        ];
    } catch (Exception $e) {
        return [0, 0];
    }
}

// ✅ Get department programs
function getDepartmentPrograms($pdo, $department_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN p.status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN p.status = 'inactive' THEN 1 ELSE 0 END) as inactive
            FROM programs p
            WHERE p.department_id = ?
        ");
        $stmt->execute([$department_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            $result['active'] ?? 0,
            $result['inactive'] ?? 0
        ];
    } catch (Exception $e) {
        return [0, 0];
    }
}

// ✅ Get department courses/subjects
function getDepartmentCourses($pdo, $department_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN s.status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN s.status = 'inactive' THEN 1 ELSE 0 END) as inactive
            FROM subject s
            WHERE s.department_id = ?
        ");
        $stmt->execute([$department_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            $result['active'] ?? 0,
            $result['inactive'] ?? 0
        ];
    } catch (Exception $e) {
        return [0, 0];
    }
}

// ✅ Get department classes
function getDepartmentClasses($pdo, $department_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN c.status = 'inactive' THEN 1 ELSE 0 END) as inactive
            FROM classes c
            WHERE c.department_id = ?
        ");
        $stmt->execute([$department_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            $result['active'] ?? 0,
            $result['inactive'] ?? 0
        ];
    } catch (Exception $e) {
        return [0, 0];
    }
}

// ✅ Get all stats for this department
$stats = [
    'teachers' => getDepartmentTeachers($pdo, $department_id),
    'students' => getDepartmentStudents($pdo, $department_id),
    'programs' => getDepartmentPrograms($pdo, $department_id),
    'courses' => getDepartmentCourses($pdo, $department_id),
    'classes' => getDepartmentClasses($pdo, $department_id),
];

// Calculate totals for welcome banner
$total_students = $stats['students'][0] + $stats['students'][1];
$total_teachers = $stats['teachers'][0] + $stats['teachers'][1];
$total_courses = $stats['courses'][0] + $stats['courses'][1];
$total_programs = $stats['programs'][0] + $stats['programs'][1];

// ✅ Get recent activity for this department
$recent_activities = [];
try {
    $activityStmt = $pdo->prepare("
        SELECT 'teacher' as type, 
               CONCAT(t.first_name, ' ', t.last_name) as title, 
               'Teacher activity' as description, 
               t.updated_at as date
        FROM teachers t 
        WHERE t.department_id = ?
        ORDER BY t.updated_at DESC 
        LIMIT 3
        UNION
        SELECT 'student' as type, 
               CONCAT(s.first_name, ' ', s.last_name) as title, 
               'Student activity' as description, 
               s.updated_at as date
        FROM students s 
        WHERE s.department_id = ?
        ORDER BY s.updated_at DESC 
        LIMIT 3
        UNION
        SELECT 'course' as type, 
               s.subject_name as title, 
               'Course updated' as description, 
               s.updated_at as date
        FROM subject s 
        WHERE s.department_id = ?
        ORDER BY s.updated_at DESC 
        LIMIT 2
        ORDER BY date DESC 
        LIMIT 8
    ");
    $activityStmt->execute([$department_id, $department_id, $department_id]);
    $recent_activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_activities = [];
}

// ✅ Get gender distribution for this department
$gender_data = [];
try {
    $genderStmt = $pdo->prepare("
        SELECT 
            CASE 
                WHEN gender IN ('Male', 'male', 'M') THEN 'Male'
                WHEN gender IN ('Female', 'female', 'F') THEN 'Female'
                ELSE 'Other' 
            END as gender,
            COUNT(*) as count
        FROM students 
        WHERE department_id = ? AND gender IS NOT NULL 
        GROUP BY 
            CASE 
                WHEN gender IN ('Male', 'male', 'M') THEN 'Male'
                WHEN gender IN ('Female', 'female', 'F') THEN 'Female'
                ELSE 'Other' 
            END
    ");
    $genderStmt->execute([$department_id]);
    $gender_data = $genderStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $gender_data = [];
}

// ✅ Get program enrollment for this department
$program_enrollment = [];
try {
    $programStmt = $pdo->prepare("
        SELECT 
            p.program_name as name,
            COUNT(s.student_id) as students,
            SUM(CASE WHEN s.status = 'active' THEN 1 ELSE 0 END) as active
        FROM programs p
        LEFT JOIN students s ON p.program_id = s.program_id
        WHERE p.department_id = ?
        GROUP BY p.program_id, p.program_name
        ORDER BY students DESC
        LIMIT 6
    ");
    $programStmt->execute([$department_id]);
    $program_enrollment = $programStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $program_enrollment = [];
}

// ✅ Get teacher qualification data for this department
$teacher_qualification = [];
try {
    $qualStmt = $pdo->prepare("
        SELECT 
            CASE 
                WHEN qualification IS NULL OR qualification = '' THEN 'Not Specified'
                ELSE qualification 
            END as qualification,
            COUNT(*) as count
        FROM teachers 
        WHERE department_id = ?
        GROUP BY 
            CASE 
                WHEN qualification IS NULL OR qualification = '' THEN 'Not Specified'
                ELSE qualification 
            END
        ORDER BY count DESC
        LIMIT 6
    ");
    $qualStmt->execute([$department_id]);
    $teacher_qualification = $qualStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $teacher_qualification = [];
}

// ✅ Get monthly enrollment data for this department
$monthly_data = [];
try {
    $monthlyStmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(s.created_at, '%b') as month,
            COUNT(*) as students,
            SUM(CASE WHEN s.status = 'active' THEN 1 ELSE 0 END) as active
        FROM students s 
        WHERE s.department_id = ? AND s.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(s.created_at, '%Y-%m'), DATE_FORMAT(s.created_at, '%b')
        ORDER BY DATE_FORMAT(s.created_at, '%Y-%m')
    ");
    $monthlyStmt->execute([$department_id]);
    $monthly_data = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $monthly_data = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Department Dashboard | <?= htmlspecialchars($department_name) ?> - Hormuud University</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ==============================
   DASHBOARD BASE - Same as campus admin but with department theme
   ============================== */
.dashboard-wrapper {
    margin-left: 240px;
    padding: 80px 25px 40px;
    transition: margin-left 0.3s ease;
    min-height: calc(100vh - 130px);
    background: #f8fafc;
}

body.sidebar-collapsed .dashboard-wrapper {
    margin-left: 60px;
}

/* Department theme colors */
:root {
    --dept-primary: #2196F3;  /* Blue for department */
    --dept-secondary: #1976D2;
    --dept-accent: #FFB400;
    --dept-gradient: linear-gradient(135deg, #2196F3 0%, #1976D2 100%);
}

/* Welcome Card with Department Theme */
.welcome-section {
    margin-bottom: 30px;
}

.welcome-card {
    background: var(--dept-gradient);
    color: white;
    border-radius: 20px;
    padding: 35px 40px;
    box-shadow: 0 20px 40px rgba(33, 150, 243, 0.2);
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

.department-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.3);
    padding: 8px 18px;
    border-radius: 20px;
    font-size: 14px;
    margin: 10px 0;
    border: 1px solid rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
}

.department-badge i {
    font-size: 16px;
}

.hierarchy-info {
    display: flex;
    gap: 20px;
    margin: 15px 0;
    flex-wrap: wrap;
}

.hierarchy-item {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.15);
    padding: 6px 15px;
    border-radius: 20px;
    font-size: 13px;
    border: 1px solid rgba(255, 255, 255, 0.1);
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

/* Stats Cards Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 25px;
    margin-bottom: 40px;
}

.stat-card {
    background: white;
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    transition: all 0.3s ease;
    border-left: 5px solid;
    position: relative;
    overflow: hidden;
    border: 1px solid #eef2f7;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
    border-color: transparent;
}

.stat-card.teacher { --card-color: #2196F3; border-left-color: #2196F3; }
.stat-card.student { --card-color: #4CAF50; border-left-color: #4CAF50; }
.stat-card.program { --card-color: #FFB400; border-left-color: #FFB400; }
.stat-card.course { --card-color: #9C27B0; border-left-color: #9C27B0; }
.stat-card.class { --card-color: #00BCD4; border-left-color: #00BCD4; }

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
}

.card-title h3 {
    font-size: 15px;
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
    font-style: italic;
}

.card-icon {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, var(--card-color), color-mix(in srgb, var(--card-color) 70%, white));
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
    flex-shrink: 0;
}

.card-content {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
}

.card-numbers .main-count {
    font-size: 32px;
    font-weight: 700;
    color: var(--card-color);
    line-height: 1;
    margin-bottom: 8px;
}

.card-numbers .sub-count {
    font-size: 13px;
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
    font-size: 18px;
    font-weight: 600;
    color: var(--card-color);
    margin-bottom: 5px;
}

.progress-label {
    font-size: 11px;
    color: #999;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Charts Grid */
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
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    border: 1px solid #eef2f7;
}

.chart-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
    border-color: transparent;
}

.chart-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--dept-gradient);
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.chart-header h3 {
    font-size: 16px;
    font-weight: 600;
    color: #333;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.chart-header h3 i {
    color: var(--dept-primary);
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
    background: var(--dept-primary);
    color: white;
    border-color: transparent;
    transform: scale(1.1);
}

.chart-container {
    position: relative;
    height: 250px;
    width: 100%;
}

/* Activity Card */
.activity-card {
    background: white;
    border-radius: 16px;
    padding: 30px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    margin-bottom: 40px;
    position: relative;
    overflow: hidden;
    border: 1px solid #eef2f7;
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
    font-size: 18px;
    font-weight: 600;
    color: #333;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.activity-header h3 i {
    color: #FFB400;
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
    border-radius: 12px;
    transition: all 0.3s ease;
    background: #f8fafc;
    border: 1px solid #eef2f7;
    position: relative;
    overflow: hidden;
}

.activity-item:hover {
    background: white;
    transform: translateX(5px);
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
    border-color: transparent;
}

.activity-item::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: var(--activity-color);
}

.activity-item.teacher { --activity-color: #2196F3; }
.activity-item.student { --activity-color: #4CAF50; }
.activity-item.course { --activity-color: #9C27B0; }

.activity-icon {
    width: 45px;
    height: 45px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: white;
    flex-shrink: 0;
}

.activity-icon.teacher { background: #2196F3; }
.activity-icon.student { background: #4CAF50; }
.activity-icon.course { background: #9C27B0; }

.activity-content {
    flex: 1;
}

.activity-title {
    font-weight: 600;
    color: #333;
    margin-bottom: 5px;
    font-size: 15px;
}

.activity-desc {
    font-size: 13px;
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

/* Quick Actions */
.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin-top: 25px;
}

.action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 12px 20px;
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 10px;
    color: #555;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
}

.action-btn:hover {
    background: var(--dept-primary);
    color: white;
    border-color: transparent;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(33, 150, 243, 0.2);
}

.action-btn i {
    font-size: 16px;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
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
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .quick-actions {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 480px) {
    .quick-actions {
        grid-template-columns: 1fr;
    }
}

/* Animations */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
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

.chart-card:nth-child(1) { animation-delay: 0.2s; }
.chart-card:nth-child(2) { animation-delay: 0.3s; }
.chart-card:nth-child(3) { animation-delay: 0.4s; }
.chart-card:nth-child(4) { animation-delay: 0.5s; }
.chart-card:nth-child(5) { animation-delay: 0.6s; }
.chart-card:nth-child(6) { animation-delay: 0.7s; }
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
                    <i class="fa-solid fa-building"></i>
                    Department Dashboard
                </h1>                
            </div>
        </div>
    </div>

    <!-- Stats Cards Grid -->
    <div class="stats-grid">
        <?php
        $cardData = [
            'teacher' => ['icon' => 'fa-user-tie', 'title' => 'Teachers', 'data' => $stats['teachers']],
            'student' => ['icon' => 'fa-user-graduate', 'title' => 'Students', 'data' => $stats['students']],
            'program' => ['icon' => 'fa-layer-group', 'title' => 'Programs', 'data' => $stats['programs']],
            'course' => ['icon' => 'fa-book', 'title' => 'Courses', 'data' => $stats['courses']],
            'class' => ['icon' => 'fa-chalkboard', 'title' => 'Classes', 'data' => $stats['classes']],
        ];
        
        foreach ($cardData as $key => $card):
            $active = $card['data'][0];
            $inactive = $card['data'][1];
            $total = $active + $inactive;
            $percentage = $total > 0 ? round(($active / $total) * 100) : 0;
        ?>
        <div class="stat-card <?= $key ?>">
            <div class="card-header">
                <div class="card-title">
                    <h3><?= $card['title'] ?></h3>
                    <p><?= htmlspecialchars($department_name) ?></p>
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

    <!-- Quick Actions -->
    <div class="quick-actions">
        <a href="../teachers/add.php?department_id=<?= $department_id ?>" class="action-btn">
            <i class="fa-solid fa-user-plus"></i> Add Teacher
        </a>
        <a href="../students/add.php?department_id=<?= $department_id ?>" class="action-btn">
            <i class="fa-solid fa-user-graduate"></i> Add Student
        </a>
        <a href="../programs/add.php?department_id=<?= $department_id ?>" class="action-btn">
            <i class="fa-solid fa-layer-group"></i> Add Program
        </a>
        <a href="../subjects/add.php?department_id=<?= $department_id ?>" class="action-btn">
            <i class="fa-solid fa-book"></i> Add Course
        </a>
        <a href="../classes/add.php?department_id=<?= $department_id ?>" class="action-btn">
            <i class="fa-solid fa-chalkboard"></i> Add Class
        </a>
        <a href="../reports/department.php?department_id=<?= $department_id ?>" class="action-btn">
            <i class="fa-solid fa-chart-simple"></i> Reports
        </a>
    </div>

    <!-- Charts Section -->
    <div class="charts-section">
        <!-- Row 1: 3 Charts -->
        <div class="chart-row">
            <!-- Chart 1: Department Overview -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fa-solid fa-chart-column"></i> Department Overview</h3>
                    <div class="chart-actions">
                        <button class="chart-btn" title="Refresh"><i class="fa-solid fa-rotate"></i></button>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="barChart"></canvas>
                </div>
            </div>
            
            <!-- Chart 2: Gender Distribution -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fa-solid fa-venn-diagram"></i> Student Gender</h3>
                    <div class="chart-actions">
                        <button class="chart-btn" title="Refresh"><i class="fa-solid fa-rotate"></i></button>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="genderChart"></canvas>
                </div>
            </div>
            
            <!-- Chart 3: Student Growth -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fa-solid fa-chart-line"></i> Student Growth</h3>
                    <div class="chart-actions">
                        <button class="chart-btn" title="Refresh"><i class="fa-solid fa-rotate"></i></button>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="growthChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Row 2: 3 Charts -->
        <div class="chart-row">
            <!-- Chart 4: Program Enrollment -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fa-solid fa-graduation-cap"></i> Program Enrollment</h3>
                    <div class="chart-actions">
                        <button class="chart-btn" title="Refresh"><i class="fa-solid fa-rotate"></i></button>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="programChart"></canvas>
                </div>
            </div>
            
         
            
            <!-- Chart 6: Active vs Inactive -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fa-solid fa-chart-pie"></i> Active Status</h3>
                    <div class="chart-actions">
                        <button class="chart-btn" title="Refresh"><i class="fa-solid fa-rotate"></i></button>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="pieChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Activity Feed -->
    <div class="activity-card">
        <div class="activity-header">
            <h3><i class="fa-solid fa-clock-rotate-left"></i> Recent Activity - <?= htmlspecialchars($department_name) ?></h3>
            <button class="chart-btn" onclick="location.reload()" title="Refresh">
                <i class="fa-solid fa-rotate"></i>
            </button>
        </div>
        <div class="activity-list">
            <?php if (empty($recent_activities)): ?>
                <div class="activity-item">
                    <div class="activity-icon teacher">
                        <i class="fa-solid fa-info-circle"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-title">No Recent Activity</div>
                        <div class="activity-desc">No activities found for your department</div>
                        <div class="activity-time">
                            <i class="fa-solid fa-clock"></i> Just now
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($recent_activities as $activity): ?>
                    <div class="activity-item <?= $activity['type'] ?>">
                        <div class="activity-icon <?= $activity['type'] ?>">
                            <i class="fa-solid fa-<?= 
                                $activity['type'] === 'teacher' ? 'chalkboard-user' : 
                                ($activity['type'] === 'student' ? 'user-graduate' : 'book') 
                            ?>"></i>
                        </div>
                        <div class="activity-content">
                            <div class="activity-title"><?= htmlspecialchars($activity['title'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="activity-desc"><?= htmlspecialchars($activity['description'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="activity-time">
                                <i class="fa-solid fa-clock"></i> <?= date("d M Y, h:i A", strtotime($activity['date'])) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ✅ FOOTER -->
<?php include('../includes/footer.php'); ?>

<script>
// Chart Colors
const chartColors = {
    primary: ['#2196F3', '#4CAF50', '#FFB400', '#9C27B0', '#00BCD4', '#E91E63'],
    secondary: ['#1976D2', '#388E3C', '#F57C00', '#7B1FA2', '#0097A7', '#C2185B']
};

// Create gradient
function createGradient(ctx, color1, color2) {
    const gradient = ctx.createLinearGradient(0, 0, 0, 250);
    gradient.addColorStop(0, color1);
    gradient.addColorStop(1, color2);
    return gradient;
}

// Chart Data
const labels = ['Teachers', 'Students', 'Programs', 'Courses', 'Classes'];
const activeData = [<?= $stats['teachers'][0] ?>, <?= $stats['students'][0] ?>, <?= $stats['programs'][0] ?>, <?= $stats['courses'][0] ?>, <?= $stats['classes'][0] ?>];
const inactiveData = [<?= $stats['teachers'][1] ?>, <?= $stats['students'][1] ?>, <?= $stats['programs'][1] ?>, <?= $stats['courses'][1] ?>, <?= $stats['classes'][1] ?>];

// Gender Data
const genderLabels = <?= json_encode(array_column($gender_data, 'gender')) ?>;
const genderCounts = <?= json_encode(array_column($gender_data, 'count')) ?>;

// Program Data
const programLabels = <?= json_encode(array_column($program_enrollment, 'name')) ?>;
const programStudents = <?= json_encode(array_column($program_enrollment, 'students')) ?>;

// Qualification Data
const qualLabels = <?= json_encode(array_column($teacher_qualification, 'qualification')) ?>;
const qualCounts = <?= json_encode(array_column($teacher_qualification, 'count')) ?>;

// Monthly Data
const monthLabels = <?= json_encode(array_column($monthly_data, 'month')) ?>;
const monthStudents = <?= json_encode(array_column($monthly_data, 'students')) ?>;
const monthActive = <?= json_encode(array_column($monthly_data, 'active')) ?>;

// Counter animation
document.addEventListener('DOMContentLoaded', function() {
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
});

// 1. Bar Chart
if (document.getElementById('barChart')) {
    const ctx = document.getElementById('barChart').getContext('2d');
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Active',
                    data: activeData,
                    backgroundColor: createGradient(ctx, '#2196F3', '#90CAF9'),
                    borderRadius: 8,
                    barPercentage: 0.6
                },
                {
                    label: 'Inactive',
                    data: inactiveData,
                    backgroundColor: createGradient(ctx, '#FF9800', '#FFB74D'),
                    borderRadius: 8,
                    barPercentage: 0.6
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                x: { grid: { display: false } },
                y: { beginAtZero: true }
            }
        }
    });
}

// 2. Gender Chart
if (document.getElementById('genderChart') && genderLabels.length > 0) {
    const ctx = document.getElementById('genderChart').getContext('2d');
    
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: genderLabels,
            datasets: [{
                data: genderCounts,
                backgroundColor: ['#2196F3', '#E91E63', '#9C27B0'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}

// 3. Growth Chart
if (document.getElementById('growthChart')) {
    const ctx = document.getElementById('growthChart').getContext('2d');
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: monthLabels.length > 0 ? monthLabels : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
            datasets: [
                {
                    label: 'Total Students',
                    data: monthStudents.length > 0 ? monthStudents : [50, 65, 80, 95, 110, 130],
                    borderColor: '#2196F3',
                    backgroundColor: 'rgba(33, 150, 243, 0.1)',
                    fill: true,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' }
            }
        }
    });
}

// 4. Program Chart
if (document.getElementById('programChart') && programLabels.length > 0) {
    const ctx = document.getElementById('programChart').getContext('2d');
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: programLabels,
            datasets: [{
                label: 'Students',
                data: programStudents,
                backgroundColor: createGradient(ctx, '#4CAF50', '#81C784'),
                borderRadius: 8
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            }
        }
    });
}

// 5. Qualification Chart
if (document.getElementById('qualChart') && qualLabels.length > 0) {
    const ctx = document.getElementById('qualChart').getContext('2d');
    
    new Chart(ctx, {
        type: 'pie',
        data: {
            labels: qualLabels,
            datasets: [{
                data: qualCounts,
                backgroundColor: chartColors.primary,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}

// 6. Pie Chart
if (document.getElementById('pieChart')) {
    const ctx = document.getElementById('pieChart').getContext('2d');
    
    const totalActive = activeData.reduce((a, b) => a + b, 0);
    const totalInactive = inactiveData.reduce((a, b) => a + b, 0);
    
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Active', 'Inactive'],
            datasets: [{
                data: [totalActive, totalInactive],
                backgroundColor: ['#4CAF50', '#FF9800'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '60%',
            plugins: {
                legend: { position: 'bottom' }
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

document.querySelectorAll('.stat-card, .chart-card, .activity-card').forEach(card => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(20px)';
    card.style.transition = 'all 0.6s ease';
    observer.observe(card);
});

// Chart refresh functionality
document.querySelectorAll('.chart-btn .fa-rotate').forEach(btn => {
    btn.closest('.chart-btn').addEventListener('click', function() {
        const chartCard = this.closest('.chart-card');
        const icon = this;
        
        icon.classList.add('fa-spin');
        chartCard.style.opacity = '0.7';
        
        setTimeout(() => {
            icon.classList.remove('fa-spin');
            chartCard.style.opacity = '1';
            
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 100px;
                right: 20px;
                background: #2196F3;
                color: white;
                padding: 12px 20px;
                border-radius: 8px;
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