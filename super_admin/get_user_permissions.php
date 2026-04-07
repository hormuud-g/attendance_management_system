<?php
session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// Check admin access
if (!isset($_SESSION['user']) || strtolower($_SESSION['user']['role']) !== 'super_admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$user_id = intval($_GET['user_id'] ?? 0);
if (!$user_id) {
    echo json_encode(['status' => 'error', 'message' => 'User ID required']);
    exit;
}

// Fetch user data
$stmt = $pdo->prepare("
    SELECT user_id, username, first_name, last_name, email, role, status
    FROM users WHERE user_id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(['status' => 'error', 'message' => 'User not found']);
    exit;
}

// Fetch user permissions
$stmt = $pdo->prepare("
    SELECT menu_item, status 
    FROM user_permissions 
    WHERE user_id = ?
");
$stmt->execute([$user_id]);
$permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$user_permissions = [];
foreach ($permissions as $perm) {
    $user_permissions[$perm['menu_item']] = $perm['status'];
}

// Menu items by role
$menu_items_by_role = [
    'super_admin' => [
        'dashboard' => 'Dashboard',
        'university_menu' => 'University Menu',
        'campus' => 'Campus',
        'faculty' => 'Faculty',
        'department' => 'Department',
        'rooms' => 'Rooms',
        'room_allocation' => 'Room Allocation',
        'academic_menu' => 'Academic Menu',
        'academic_years' => 'Academic Years',
        'academic_terms' => 'Academic Terms',
        'semesters' => 'Semesters',
        'programs' => 'Programs',
        'classes' => 'Classes',
        'courses' => 'Courses',
        'recourse_assign' => 'Recourse Assign',
        'student_enroll' => 'Student Enroll',
        'timetable' => 'Timetable',
        'promotion' => 'Promotion',
        'attendance' => 'Attendance',
        'people_menu' => 'People Menu',
        'teachers' => 'Teachers',
        'students' => 'Students',
        'parents' => 'Parents',
        'users' => 'User Accounts',
        'reports_menu' => 'Reports Menu',
        'attendance_report' => 'Attendance Report',
        'promotion_report' => 'Promotion Report',
        'reports_overview' => 'Reports Overview',
        'announcements' => 'Announcements',
        'settings_menu' => 'Settings Menu',
        'notifications' => 'Notifications',
        'audit_logs' => 'Audit Logs'
    ],
    // ... other roles as in the main file
];

$role = $user['role'];
$menu_items = $menu_items_by_role[$role] ?? [];

echo json_encode([
    'status' => 'success',
    'user' => $user,
    'permissions' => $user_permissions,
    'menu_items' => $menu_items
]);
?>