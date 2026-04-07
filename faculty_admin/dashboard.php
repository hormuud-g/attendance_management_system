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
$linked_id = $user['linked_id'] ?? 0;

// ✅ Verify role
if (!in_array($role, ['campus_admin', 'faculty_admin'])) {
    header("Location: ../unauthorized.php");
    exit;
}

// ✅ Get user details
$first_name = $last_name = $display_name = $entity_name = $campus_name = '';
$entity_id = $linked_id;
$entity_type = $role === 'campus_admin' ? 'campus' : 'faculty';

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
$name = $display_name ?: ucfirst($user['username'] ?? 'User');

// ✅ Get entity name
if ($role === 'campus_admin') {
    $stmt = $pdo->prepare("SELECT campus_name FROM campus WHERE campus_id = ?");
    $stmt->execute([$entity_id]);
    $entity_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $entity_name = $entity_data['campus_name'] ?? 'Unknown Campus';
} else {
    $stmt = $pdo->prepare("
        SELECT f.faculty_name,
               (SELECT campus_name FROM campus c
                JOIN faculty_campus fc ON c.campus_id = fc.campus_id
                WHERE fc.faculty_id = f.faculty_id LIMIT 1) as campus_name
        FROM faculties f
        WHERE f.faculty_id = ?
    ");
    $stmt->execute([$entity_id]);
    $entity_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $entity_name = $entity_data['faculty_name'] ?? 'Unknown Faculty';
    $campus_name = $entity_data['campus_name'] ?? '';
}

// ✅ Include header
require_once('../includes/header.php');

// ============================================
// ✅ STATS FUNCTIONS (Based on your database)
// ============================================

/**
 * Simple count for tables with or without status column
 */
function getSimpleCount($pdo, $table, $status = 'active') {
    try {
        $checkStmt = $pdo->prepare("SHOW COLUMNS FROM $table LIKE 'status'");
        $checkStmt->execute();
        $has_status = $checkStmt->fetch();

        if ($has_status) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE status = ?");
            $stmt->execute([$status]);
            return $stmt->fetchColumn() ?: 0;
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
            return $stmt->fetchColumn() ?: 0;
        }
    } catch (Exception $e) {
        error_log("Error counting $table: " . $e->getMessage());
        return 0;
    }
}

/**
 * Count teachers for a campus or faculty
 */
function getTeacherCount($pdo, $role, $entity_id) {
    try {
        if ($role === 'campus_admin') {
            // Teachers teaching subjects in this campus (via timetable)
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT t.teacher_id)
                FROM teachers t
                JOIN timetable tt ON t.teacher_id = tt.teacher_id
                WHERE tt.campus_id = ? AND t.status = 'active'
            ");
            $stmt->execute([$entity_id]);
            $active = $stmt->fetchColumn() ?: 0;

            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT t.teacher_id)
                FROM teachers t
                JOIN timetable tt ON t.teacher_id = tt.teacher_id
                WHERE tt.campus_id = ? AND t.status = 'inactive'
            ");
            $stmt->execute([$entity_id]);
            $inactive = $stmt->fetchColumn() ?: 0;
        } else {
            // Teachers teaching subjects in this faculty (via timetable)
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT t.teacher_id)
                FROM teachers t
                JOIN timetable tt ON t.teacher_id = tt.teacher_id
                WHERE tt.faculty_id = ? AND t.status = 'active'
            ");
            $stmt->execute([$entity_id]);
            $active = $stmt->fetchColumn() ?: 0;

            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT t.teacher_id)
                FROM teachers t
                JOIN timetable tt ON t.teacher_id = tt.teacher_id
                WHERE tt.faculty_id = ? AND t.status = 'inactive'
            ");
            $stmt->execute([$entity_id]);
            $inactive = $stmt->fetchColumn() ?: 0;
        }
        return [$active, $inactive];
    } catch (Exception $e) {
        error_log("Error counting teachers: " . $e->getMessage());
        return [0, 0];
    }
}

/**
 * Count parents for a campus or faculty
 */
function getParentCount($pdo, $role, $entity_id) {
    try {
        if ($role === 'campus_admin') {
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT p.parent_id)
                FROM parents p
                JOIN parent_student ps ON p.parent_id = ps.parent_id
                JOIN students s ON ps.student_id = s.student_id
                WHERE s.campus_id = ? AND p.status = 'active'
            ");
            $stmt->execute([$entity_id]);
            $active = $stmt->fetchColumn() ?: 0;

            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT p.parent_id)
                FROM parents p
                JOIN parent_student ps ON p.parent_id = ps.parent_id
                JOIN students s ON ps.student_id = s.student_id
                WHERE s.campus_id = ? AND p.status = 'inactive'
            ");
            $stmt->execute([$entity_id]);
            $inactive = $stmt->fetchColumn() ?: 0;
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT p.parent_id)
                FROM parents p
                JOIN parent_student ps ON p.parent_id = ps.parent_id
                JOIN students s ON ps.student_id = s.student_id
                JOIN departments d ON s.department_id = d.department_id
                WHERE d.faculty_id = ? AND p.status = 'active'
            ");
            $stmt->execute([$entity_id]);
            $active = $stmt->fetchColumn() ?: 0;

            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT p.parent_id)
                FROM parents p
                JOIN parent_student ps ON p.parent_id = ps.parent_id
                JOIN students s ON ps.student_id = s.student_id
                JOIN departments d ON s.department_id = d.department_id
                WHERE d.faculty_id = ? AND p.status = 'inactive'
            ");
            $stmt->execute([$entity_id]);
            $inactive = $stmt->fetchColumn() ?: 0;
        }
        return [$active, $inactive];
    } catch (Exception $e) {
        error_log("Error counting parents: " . $e->getMessage());
        return [0, 0];
    }
}

/**
 * Count announcements for a campus or faculty
 * Note: announcement table doesn't have campus_id/faculty_id, so we use created_by user's role
 */
function getAnnouncementCount($pdo, $role, $entity_id) {
    try {
        if ($role === 'campus_admin') {
            // Get all campus admin user IDs for this campus
            $stmt = $pdo->prepare("
                SELECT user_id FROM users
                WHERE linked_table = 'campus' AND linked_id = ? AND role = 'campus_admin'
            ");
            $stmt->execute([$entity_id]);
            $admin_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($admin_ids)) return [0, 0];

            $placeholders = implode(',', array_fill(0, count($admin_ids), '?'));

            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM announcement
                WHERE created_by IN ($placeholders) AND status = 'active'
            ");
            $stmt->execute($admin_ids);
            $active = $stmt->fetchColumn() ?: 0;

            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM announcement
                WHERE created_by IN ($placeholders) AND status = 'inactive'
            ");
            $stmt->execute($admin_ids);
            $inactive = $stmt->fetchColumn() ?: 0;
        } else {
            // Get all faculty admin user IDs for this faculty
            $stmt = $pdo->prepare("
                SELECT user_id FROM users
                WHERE linked_table = 'faculty' AND linked_id = ? AND role = 'faculty_admin'
            ");
            $stmt->execute([$entity_id]);
            $admin_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($admin_ids)) return [0, 0];

            $placeholders = implode(',', array_fill(0, count($admin_ids), '?'));

            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM announcement
                WHERE created_by IN ($placeholders) AND status = 'active'
            ");
            $stmt->execute($admin_ids);
            $active = $stmt->fetchColumn() ?: 0;

            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM announcement
                WHERE created_by IN ($placeholders) AND status = 'inactive'
            ");
            $stmt->execute($admin_ids);
            $inactive = $stmt->fetchColumn() ?: 0;
        }
        return [$active, $inactive];
    } catch (Exception $e) {
        error_log("Error counting announcements: " . $e->getMessage());
        return [0, 0];
    }
}

// ✅ Get all stats
$stats = [];

if ($role === 'campus_admin') {
    $stats = [
        'faculties' => [getSimpleCount($pdo, 'faculties', 'active'), getSimpleCount($pdo, 'faculties', 'inactive')],
        'departments' => [getSimpleCount($pdo, 'departments', 'active'), getSimpleCount($pdo, 'departments', 'inactive')],
        'programs' => [getSimpleCount($pdo, 'programs', 'active'), getSimpleCount($pdo, 'programs', 'inactive')],
        'teachers' => getTeacherCount($pdo, $role, $entity_id),
        'students' => [getSimpleCount($pdo, 'students', 'active'), getSimpleCount($pdo, 'students', 'inactive')],
        'parents' => getParentCount($pdo, $role, $entity_id),
        'announcements' => getAnnouncementCount($pdo, $role, $entity_id),
        'classes' => [getSimpleCount($pdo, 'classes', 'Active'), getSimpleCount($pdo, 'classes', 'Inactive')],
        'rooms' => [getSimpleCount($pdo, 'rooms', 'available'), getSimpleCount($pdo, 'rooms', 'inactive')],
        'courses' => [getSimpleCount($pdo, 'subject', 'active'), getSimpleCount($pdo, 'subject', 'inactive')],
    ];
} else {
    $stats = [
        'departments' => [getSimpleCount($pdo, 'departments', 'active'), getSimpleCount($pdo, 'departments', 'inactive')],
        'programs' => [getSimpleCount($pdo, 'programs', 'active'), getSimpleCount($pdo, 'programs', 'inactive')],
        'teachers' => getTeacherCount($pdo, $role, $entity_id),
        'students' => [getSimpleCount($pdo, 'students', 'active'), getSimpleCount($pdo, 'students', 'inactive')],
        'parents' => getParentCount($pdo, $role, $entity_id),
        'announcements' => getAnnouncementCount($pdo, $role, $entity_id),
        'classes' => [getSimpleCount($pdo, 'classes', 'Active'), getSimpleCount($pdo, 'classes', 'Inactive')],
        'rooms' => [getSimpleCount($pdo, 'rooms', 'available'), getSimpleCount($pdo, 'rooms', 'inactive')],
        'courses' => [getSimpleCount($pdo, 'subject', 'active'), getSimpleCount($pdo, 'subject', 'inactive')],
    ];
}

// ✅ Get recent activities
$recent_activities = [];
try {
    $activityStmt = $pdo->prepare("
        (SELECT 'user' as type, username as title, 'New user registered' as description, created_at as date
         FROM users ORDER BY created_at DESC LIMIT 2)
        UNION
        (SELECT 'announcement' as type, title, 'New announcement posted' as description, created_at as date
         FROM announcement ORDER BY created_at DESC LIMIT 2)
        UNION
        (SELECT 'teacher' as type, teacher_name as title, 'New teacher added' as description, created_at as date
         FROM teachers ORDER BY created_at DESC LIMIT 2)
        UNION
        (SELECT 'student' as type, full_name as title, 'New student enrolled' as description, created_at as date
         FROM students ORDER BY created_at DESC LIMIT 2)
        UNION
        (SELECT 'parent' as type, full_name as title, 'New parent registered' as description, created_at as date
         FROM parents ORDER BY created_at DESC LIMIT 2)
        ORDER BY date DESC LIMIT 8
    ");
    $activityStmt->execute();
    $recent_activities = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error in recent activities: " . $e->getMessage());
}

// ✅ Get monthly enrollment data
$monthly_data = [];
try {
    $monthlyStmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(created_at, '%b') as month,
            COUNT(*) as students
        FROM students
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b')
        ORDER BY DATE_FORMAT(created_at, '%Y-%m')
    ");
    $monthlyStmt->execute();
    $monthly_data = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error in monthly data: " . $e->getMessage());
}

// ✅ Get department-wise student count
$department_students = [];
try {
    $deptStmt = $pdo->prepare("
        SELECT
            d.department_name as name,
            COUNT(s.student_id) as count
        FROM departments d
        LEFT JOIN students s ON d.department_id = s.department_id
        GROUP BY d.department_id, d.department_name
        ORDER BY count DESC
        LIMIT 8
    ");
    $deptStmt->execute();
    $department_students = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error in department students: " . $e->getMessage());
}

// ✅ Get program enrollment data
$program_enrollment = [];
try {
    $programStmt = $pdo->prepare("
        SELECT
            p.program_name as name,
            COUNT(s.student_id) as students
        FROM programs p
        LEFT JOIN students s ON p.program_id = s.program_id
        GROUP BY p.program_id, p.program_name
        ORDER BY students DESC
        LIMIT 6
    ");
    $programStmt->execute();
    $program_enrollment = $programStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error in program enrollment: " . $e->getMessage());
}

// ✅ Get room utilization data
$room_utilization = [];
try {
    $roomStmt = $pdo->prepare("
        SELECT
            status,
            COUNT(*) as count
        FROM rooms
        GROUP BY status
    ");
    $roomStmt->execute();
    $room_utilization = $roomStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error in room utilization: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $role === 'campus_admin' ? 'Campus' : 'Faculty' ?> Dashboard | Hormuud University</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        /* ==============================
           DASHBOARD BASE
           ============================== */
        :root {
            --primary-green: #00843D;
            --primary-green-dark: #005a2b;
            --primary-green-light: #4CAF50;
            --accent-gold: #FFB400;
            --accent-blue: #2196F3;
            --accent-orange: #FF9800;
            --accent-purple: #9C27B0;
            --accent-pink: #E91E63;
            --accent-teal: #00BCD4;
            --accent-indigo: #3F51B5;
            --accent-lime: #8BC34A;
            --neutral-dark: #333;
            --neutral-medium: #666;
            --neutral-light: #999;
            --neutral-lighter: #f8fafc;
            --white: #ffffff;
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 12px 24px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 40px rgba(0, 132, 61, 0.2);
        }

        .dashboard-wrapper {
            margin-left: 240px;
            padding: 80px 25px 40px;
            transition: margin-left 0.3s ease;
            min-height: calc(100vh - 130px);
            background: var(--neutral-lighter);
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
            background: linear-gradient(135deg, var(--primary-green) 0%, var(--primary-green-dark) 100%);
            color: var(--white);
            border-radius: 20px;
            padding: 35px 40px;
            box-shadow: var(--shadow-lg), 0 10px 20px rgba(0, 132, 61, 0.1), inset 0 1px 0 rgba(255, 255, 255, 0.2);
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
            background: var(--white);
            border-radius: 16px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            border-left: 5px solid;
            position: relative;
            overflow: hidden;
            border: 1px solid #eef2f7;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
            border-color: transparent;
        }

        .stat-card.faculty { --card-color: var(--primary-green); border-left-color: var(--primary-green); }
        .stat-card.department { --card-color: var(--accent-teal); border-left-color: var(--accent-teal); }
        .stat-card.program { --card-color: var(--accent-gold); border-left-color: var(--accent-gold); }
        .stat-card.teacher { --card-color: var(--accent-blue); border-left-color: var(--accent-blue); }
        .stat-card.student { --card-color: var(--primary-green-light); border-left-color: var(--primary-green-light); }
        .stat-card.parent { --card-color: var(--accent-orange); border-left-color: var(--accent-orange); }
        .stat-card.announcement { --card-color: var(--accent-pink); border-left-color: var(--accent-pink); }
        .stat-card.class { --card-color: var(--accent-indigo); border-left-color: var(--accent-indigo); }
        .stat-card.room { --card-color: var(--accent-teal); border-left-color: var(--accent-teal); }
        .stat-card.course { --card-color: var(--accent-lime); border-left-color: var(--accent-lime); }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .card-title h3 {
            font-size: 15px;
            font-weight: 600;
            color: var(--neutral-medium);
            margin: 0 0 5px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .card-title p {
            font-size: 12px;
            color: var(--neutral-light);
            margin: 0;
            font-style: italic;
        }

        .card-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--card-color), color-mix(in srgb, var(--card-color) 70%, var(--white)));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: var(--white);
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
            color: var(--neutral-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ==============================
           CHARTS SECTION
           ============================== */
        .chart-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
            margin-bottom: 25px;
        }

        .chart-card {
            background: var(--white);
            border-radius: 16px;
            padding: 25px;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border: 1px solid #eef2f7;
        }

        .chart-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
            border-color: transparent;
        }

        .chart-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-green), var(--primary-green-light));
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
            color: var(--neutral-dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-header h3 i {
            color: var(--primary-green);
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
            background: var(--white);
            color: var(--neutral-medium);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-size: 14px;
        }

        .chart-btn:hover {
            background: var(--primary-green);
            color: var(--white);
            border-color: transparent;
            transform: scale(1.1);
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
            background: var(--white);
            border-radius: 16px;
            padding: 30px;
            box-shadow: var(--shadow-sm);
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
            background: linear-gradient(90deg, var(--accent-gold), var(--accent-orange));
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
            color: var(--neutral-dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .activity-header h3 i {
            color: var(--accent-gold);
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
            background: var(--neutral-lighter);
            border: 1px solid #eef2f7;
            position: relative;
            overflow: hidden;
        }

        .activity-item:hover {
            background: var(--white);
            transform: translateX(5px);
            box-shadow: var(--shadow-sm);
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

        .activity-item.user { --activity-color: var(--primary-green); }
        .activity-item.teacher { --activity-color: var(--accent-blue); }
        .activity-item.student { --activity-color: var(--primary-green-light); }
        .activity-item.parent { --activity-color: var(--accent-orange); }
        .activity-item.announcement { --activity-color: var(--accent-pink); }

        .activity-icon {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: var(--white);
            flex-shrink: 0;
        }

        .activity-icon.user { background: var(--primary-green); }
        .activity-icon.teacher { background: var(--accent-blue); }
        .activity-icon.student { background: var(--primary-green-light); }
        .activity-icon.parent { background: var(--accent-orange); }
        .activity-icon.announcement { background: var(--accent-pink); }

        .activity-content {
            flex: 1;
        }

        .activity-title {
            font-weight: 600;
            color: var(--neutral-dark);
            margin-bottom: 5px;
            font-size: 15px;
        }

        .activity-desc {
            font-size: 13px;
            color: var(--neutral-medium);
            margin-bottom: 8px;
            line-height: 1.4;
        }

        .activity-time {
            font-size: 12px;
            color: var(--neutral-light);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* ==============================
           RESPONSIVE DESIGN
           ============================== */
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
            .chart-row { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 992px) {
            .chart-row { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .dashboard-wrapper {
                margin-left: 0 !important;
                padding: 80px 15px 40px;
            }
            .welcome-card { padding: 25px 20px; }
            .welcome-content h1 { font-size: 24px; }
            .stats-grid { grid-template-columns: 1fr; }
            .chart-card { padding: 20px; }
            .chart-container { height: 220px; }
        }

        @media (max-width: 480px) {
            .welcome-card { padding: 20px; }
            .welcome-content h1 {
                font-size: 20px;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            .welcome-content p { font-size: 14px; }
            .card-numbers .main-count { font-size: 28px; }
            .activity-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .chart-header h3 { font-size: 16px; }
        }

        /* ==============================
           ANIMATIONS
           ============================== */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .welcome-card, .stat-card, .chart-card, .activity-card {
            animation: fadeInUp 0.6s ease forwards;
            opacity: 0;
        }

        /* ==============================
           CUSTOM SCROLLBAR
           ============================== */
        .dashboard-wrapper::-webkit-scrollbar { width: 6px; }
        .dashboard-wrapper::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 3px; }
        .dashboard-wrapper::-webkit-scrollbar-thumb { background: var(--primary-green); border-radius: 3px; }
        .dashboard-wrapper::-webkit-scrollbar-thumb:hover { background: var(--primary-green-dark); }
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    <!-- Welcome Section -->
    <div class="welcome-section">
        <div class="welcome-card">
            <div class="welcome-content">
                <h1>
                    <i class="fa-solid fa-<?= $role === 'campus_admin' ? 'building-columns' : 'building' ?>"></i>
                    <?= $role === 'campus_admin' ? 'Campus Admin Dashboard' : 'Faculty Admin Dashboard' ?>
                </h1>
                
            </div>
        </div>
    </div>

    <!-- Stats Cards Grid -->
    <div class="stats-grid">
        <?php
        if ($role === 'campus_admin') {
            $cardData = [
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
            ];
        } else {
            $cardData = [
                'department' => ['icon' => 'fa-building', 'title' => 'Departments', 'data' => $stats['departments']],
                'program' => ['icon' => 'fa-layer-group', 'title' => 'Programs', 'data' => $stats['programs']],
                'teacher' => ['icon' => 'fa-user-tie', 'title' => 'Teachers', 'data' => $stats['teachers']],
                'student' => ['icon' => 'fa-user-graduate', 'title' => 'Students', 'data' => $stats['students']],
                'parent' => ['icon' => 'fa-user-group', 'title' => 'Parents', 'data' => $stats['parents']],
                'announcement' => ['icon' => 'fa-bullhorn', 'title' => 'Announcements', 'data' => $stats['announcements']],
                'class' => ['icon' => 'fa-chalkboard', 'title' => 'Classes', 'data' => $stats['classes']],
                'room' => ['icon' => 'fa-door-open', 'title' => 'Rooms', 'data' => $stats['rooms']],
                'course' => ['icon' => 'fa-book', 'title' => 'Courses', 'data' => $stats['courses']],
            ];
        }

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
                    <p><?= $role === 'campus_admin' ? 'Campus' : 'Faculty' ?>: <?= htmlspecialchars($entity_name, ENT_QUOTES, 'UTF-8') ?></p>
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

    <!-- Charts Section -->
    <div class="charts-section">
        <div class="chart-row">
            <!-- Chart 1: Overview -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fa-solid fa-chart-column"></i> Overview</h3>
                </div>
                <div class="chart-container">
                    <canvas id="barChart"></canvas>
                </div>
            </div>

            <!-- Chart 2: Active Distribution -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fa-solid fa-chart-pie"></i> Active Distribution</h3>
                </div>
                <div class="chart-container">
                    <canvas id="pieChart"></canvas>
                </div>
            </div>

            <!-- Chart 3: Student Growth -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fa-solid fa-chart-line"></i> Student Growth</h3>
                </div>
                <div class="chart-container">
                    <canvas id="growthChart"></canvas>
                </div>
            </div>
        </div>

        <div class="chart-row">
            <!-- Chart 4: Department Students -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fa-solid fa-building"></i> Department Students</h3>
                </div>
                <div class="chart-container">
                    <canvas id="deptChart"></canvas>
                </div>
            </div>

            <!-- Chart 5: Program Enrollment -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fa-solid fa-graduation-cap"></i> Program Enrollment</h3>
                </div>
                <div class="chart-container">
                    <canvas id="programChart"></canvas>
                </div>
            </div>

            <!-- Chart 6: Room Utilization -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fa-solid fa-door-closed"></i> Room Utilization</h3>
                </div>
                <div class="chart-container">
                    <canvas id="roomChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Activity Feed -->
    <div class="activity-card">
        <div class="activity-header">
            <h3><i class="fa-solid fa-clock-rotate-left"></i> Recent Activity</h3>
            <button class="chart-btn" onclick="location.reload()" title="Refresh">
                <i class="fa-solid fa-rotate"></i>
            </button>
        </div>
        <div class="activity-list">
            <?php if (empty($recent_activities)): ?>
                <div class="activity-item">
                    <div class="activity-icon user">
                        <i class="fa-solid fa-info-circle"></i>
                    </div>
                    <div class="activity-content">
                        <div class="activity-title">No Recent Activity</div>
                        <div class="activity-desc">No activities found</div>
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
                                $activity['type'] === 'user' ? 'user-plus' :
                                ($activity['type'] === 'announcement' ? 'bullhorn' :
                                ($activity['type'] === 'teacher' ? 'chalkboard-user' :
                                ($activity['type'] === 'parent' ? 'people-robbery' : 'user-graduate')))
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
        primary: ['#00843D', '#00A651', '#FFB400', '#2196F3', '#4CAF50', '#FF9800', '#9C27B0', '#E91E63', '#3F51B5', '#00BCD4'],
    };

    // Chart Data
    const labels = <?= $role === 'campus_admin'
        ? "['Faculties', 'Departments', 'Programs', 'Teachers', 'Students', 'Parents', 'Announcements', 'Classes', 'Rooms', 'Courses']"
        : "['Departments', 'Programs', 'Teachers', 'Students', 'Parents', 'Announcements', 'Classes', 'Rooms', 'Courses']"
    ?>;
    const activeData = [<?= implode(',', array_column($stats, '0')) ?>];

    // Department Data
    const deptLabels = <?= json_encode(array_column($department_students, 'name')) ?>;
    const deptCounts = <?= json_encode(array_column($department_students, 'count')) ?>;

    // Program Data
    const programLabels = <?= json_encode(array_column($program_enrollment, 'name')) ?>;
    const programStudents = <?= json_encode(array_column($program_enrollment, 'students')) ?>;

    // Room Data
    const roomLabels = <?= json_encode(array_column($room_utilization, 'status')) ?>;
    const roomCounts = <?= json_encode(array_column($room_utilization, 'count')) ?>;

    // Monthly Data
    const monthLabels = <?= json_encode(array_column($monthly_data, 'month')) ?>;
    const monthStudents = <?= json_encode(array_column($monthly_data, 'students')) ?>;

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

    // Bar Chart
    if (document.getElementById('barChart')) {
        new Chart(document.getElementById('barChart'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Active',
                    data: activeData,
                    backgroundColor: chartColors.primary,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } }
            }
        });
    }

    // Pie Chart
    if (document.getElementById('pieChart')) {
        new Chart(document.getElementById('pieChart'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: activeData,
                    backgroundColor: chartColors.primary,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '60%',
                plugins: { legend: { position: 'right' } }
            }
        });
    }

    // Growth Chart
    if (document.getElementById('growthChart')) {
        new Chart(document.getElementById('growthChart'), {
            type: 'line',
            data: {
                labels: monthLabels.length ? monthLabels : ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                datasets: [{
                    label: 'Students',
                    data: monthStudents.length ? monthStudents : [0, 0, 0, 0, 0, 0],
                    borderColor: '#00843D',
                    backgroundColor: 'rgba(0,132,61,0.1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }

    // Department Chart
    if (document.getElementById('deptChart') && deptLabels.length) {
        new Chart(document.getElementById('deptChart'), {
            type: 'bar',
            data: {
                labels: deptLabels,
                datasets: [{
                    label: 'Students',
                    data: deptCounts,
                    backgroundColor: chartColors.primary,
                    borderRadius: 6
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } }
            }
        });
    }

    // Program Chart
    if (document.getElementById('programChart') && programLabels.length) {
        new Chart(document.getElementById('programChart'), {
            type: 'radar',
            data: {
                labels: programLabels,
                datasets: [{
                    label: 'Students',
                    data: programStudents,
                    borderColor: '#00843D',
                    backgroundColor: 'rgba(0,132,61,0.1)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }

    // Room Chart
    if (document.getElementById('roomChart') && roomLabels.length) {
        new Chart(document.getElementById('roomChart'), {
            type: 'polarArea',
            data: {
                labels: roomLabels,
                datasets: [{
                    data: roomCounts,
                    backgroundColor: ['#4CAF50', '#2196F3', '#FF9800', '#9E9E9E']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false
            }
        });
    }
</script>
</body>
</html>