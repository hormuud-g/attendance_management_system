<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Redirect if not logged in
if (empty($_SESSION['user'])) {
  header("Location: ../login.php");
  exit;
}

// ✅ User Info
$user = $_SESSION['user'];
$role = strtolower(str_replace(' ', '_', $user['role'] ?? ''));
$username = htmlspecialchars($user['username'] ?? 'User', ENT_QUOTES, 'UTF-8');

// ✅ Get user data
$user_id = $user['user_id'] ?? 0;
$first_name = $last_name = '';
if ($user_id) {
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user_data) {
        $first_name = $user_data['first_name'] ?? '';
        $last_name = $user_data['last_name'] ?? '';
    }
}

// ✅ Display name
$display_name = (!empty($first_name) && !empty($last_name)) 
    ? ucfirst($first_name) . ' ' . ucfirst($last_name) 
    : ucfirst($username);

$email = htmlspecialchars($user['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
$phone = htmlspecialchars($user['phone_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8');

// ✅ Profile photo
$photo = !empty($user['profile_photo_path'])
    ? "../" . htmlspecialchars($user['profile_photo_path'], ENT_QUOTES, 'UTF-8')
    : "../upload/profiles/default.png";

if (!file_exists($photo)) {
    $photo = "../upload/profiles/default.png";
}

/* ================= FETCH USER PERMISSIONS ================= */
$user_permissions = [];

if ($user_id) {
    // Fetch user's menu permissions
    $stmt = $pdo->prepare("
        SELECT menu_item, status 
        FROM user_permissions 
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($permissions as $perm) {
        $user_permissions[$perm['menu_item']] = $perm['status'];
    }
}

/* ================= FUNCTION TO CHECK PERMISSION ================= */
function hasPermission($menu_item, $default = 'allowed') {
    global $user_permissions, $role;
    
    // Super admin always has all permissions
    if ($role === 'super_admin') {
        return true;
    }
    
    // Check if specific permission exists
    if (isset($user_permissions[$menu_item])) {
        return $user_permissions[$menu_item] === 'allowed';
    }
    
    // Return default if no specific permission
    return $default === 'allowed';
}

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HU | <?= ucfirst(str_replace('.php', '', $currentPage)) ?></title>

<link rel="icon" type="image/png" href="../images.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
/* ==============================
   BASE STYLES
   ============================== */
:root {
  --hu-green: #00843D;
  --hu-blue: #0072CE;
  --hu-light-green: #00A651;
  --hu-dark-gray: #333333;
  --hu-light-gray: #F5F9F7;
  --hu-red: #C62828;
  --hu-amber: #FFB400;
  --hu-white: #FFFFFF;
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: 'Poppins', sans-serif;
}

body {
  background: var(--hu-light-gray);
  color: var(--hu-dark-gray);
  overflow-x: hidden;
  min-height: 100vh;
}

a {
  text-decoration: none;
  color: inherit;
}

/* ==============================
   HEADER STYLES
   ============================== */
.hu-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: linear-gradient(135deg, var(--hu-blue), var(--hu-green));
  padding: 10px 20px;
  color: white;
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  height: 65px;
  z-index: 1000;
  box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
}

.hu-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.menu-btn {
  background: none;
  border: none;
  color: white;
  font-size: 20px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  border-radius: 6px;
  transition: background 0.2s;
}

.menu-btn:hover {
  background: rgba(255, 255, 255, 0.1);
}

.logo-circle {
  background: white;
  border-radius: 50%;
  padding: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.hu-logo {
  height: 36px;
  width: 36px;
  object-fit: contain;
}

.hu-title {
  font-weight: 600;
  font-size: 18px;
}

.hu-right {
  display: flex;
  align-items: center;
  gap: 15px;
}

.user-info {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  padding: 5px 8px;
  border-radius: 6px;
  transition: background 0.2s;
}

.user-info:hover {
  background: rgba(255, 255, 255, 0.1);
}

.user-photo {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  border: 2px solid white;
  object-fit: cover;
}

.logout-btn {
  background: var(--hu-red);
  color: var(--hu-white);
  padding: 7px 12px;
  border-radius: 5px;
  font-weight: 500;
  transition: background 0.2s;
  display: flex;
  align-items: center;
  gap: 5px;
}

.logout-btn:hover {
  background: #b71c1c;
}

/* ==============================
   SIDEBAR STYLES
   ============================== */
.sidebar {
  position: fixed;
  top: 65px;
  left: 0;
  width: 240px;
  height: calc(100% - 65px);
  background: linear-gradient(180deg, var(--hu-green), #007530);
  color: white;
  overflow-y: auto;
  transition: all 0.3s ease;
  padding: 12px 0;
  z-index: 999;
}

.sidebar ul {
  list-style: none;
}

.sidebar li {
  border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.sidebar a {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 16px;
  color: white;
  font-weight: 500;
  border-left: 4px solid transparent;
  transition: all 0.2s;
}

.sidebar a i {
  width: 18px;
  text-align: center;
  font-size: 15px;
}

.sidebar a:hover,
.sidebar a.active {
  background: white;
  color: var(--hu-blue);
  border-left: 4px solid var(--hu-blue);
}

.sidebar .submenu {
  display: none;
  background: rgba(0, 0, 0, 0.1);
}

.sidebar .submenu a {
  padding-left: 40px;
  font-size: 13px;
}

.sidebar .menu-toggle {
  cursor: pointer;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 16px;
  font-weight: 500;
}

.sidebar .menu-toggle:hover {
  background: rgba(255, 255, 255, 0.1);
}

.sidebar .menu-toggle i {
  transition: 0.2s;
}

.sidebar .open i {
  transform: rotate(90deg);
}

/* Collapsed State */
.sidebar.collapsed {
  width: 60px;
}

.sidebar.collapsed a span,
.sidebar.collapsed .menu-toggle span {
  display: none;
}

.sidebar.collapsed .submenu {
  display: none !important;
}

.sidebar.collapsed a,
.sidebar.collapsed .menu-toggle {
  justify-content: center;
}

/* ==============================
   MAIN CONTENT AREA
   ============================== */
.main-content {
  margin-top: 65px;
  margin-left: 240px;
  margin-bottom: 50px;
  padding: 16px;
  transition: margin-left 0.3s ease;
  min-height: calc(100vh - 115px);
}

body.sidebar-collapsed .main-content {
  margin-left: 60px;
}

/* ==============================
   FOOTER STYLES - FULL RESPONSIVE
   ============================== */



/* ==============================
   PROFILE MODAL
   ============================== */
.profile-modal {
  display: none;
  justify-content: center;
  align-items: center;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 2000;
}

.profile-content {
  background: white;
  border-radius: 8px;
  padding: 20px;
  text-align: center;
  width: 300px;
  box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
  position: relative;
}

.profile-avatar {
  width: 80px;
  height: 80px;
  border-radius: 50%;
  border: 3px solid var(--hu-green);
  object-fit: cover;
}

.close-profile {
  position: absolute;
  top: 8px;
  right: 12px;
  font-size: 20px;
  color: var(--hu-blue);
  cursor: pointer;
}

.profile-links {
  margin-top: 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.profile-link {
  background: var(--hu-blue);
  color: white;
  border: none;
  padding: 8px;
  border-radius: 5px;
  cursor: pointer;
  transition: background 0.2s;
}

.profile-link:hover {
  background: var(--hu-green);
}

.overlay {
  display: none;
  justify-content: center;
  align-items: center;
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 3000;
}

.overlay-content {
  background: white;
  border-radius: 6px;
  width: 90%;
  height: 90%;
  position: relative;
}

.close-overlay {
  position: absolute;
  top: 8px;
  right: 16px;
  font-size: 22px;
  color: var(--hu-blue);
  cursor: pointer;
}

.overlay-frame {
  width: 100%;
  height: 100%;
  border: none;
  border-radius: 6px;
}

.refresh-btn {
  background: var(--hu-green);
  color: white;
  border: none;
  padding: 7px 12px;
  border-radius: 5px;
  cursor: pointer;
  font-size: 13px;
  margin-top: 8px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: background 0.2s;
}

.refresh-btn:hover {
  background: var(--hu-blue);
}

.profile-info {
  margin: 12px 0;
}

.profile-info p {
  margin: 4px 0;
  font-size: 13px;
}

.profile-info strong {
  color: var(--hu-blue);
}

.no-photo-message {
  background: #fff3cd;
  color: #856404;
  padding: 8px;
  border-radius: 5px;
  margin: 8px 0;
  font-size: 12px;
  border: 1px solid #ffeaa7;
}

/* ==============================
   RESPONSIVE STYLES
   ============================== */

/* Tablet Devices (768px iyo ka hooseeyo) */
@media (max-width: 768px) {
  /* Header Responsive */
  .hu-header {
    padding: 8px 15px;
    height: 60px;
  }
  
  .hu-title {
    font-size: 16px;
    display: none;
  }
  
  .user-info > div {
    display: none;
  }
  
  .user-photo {
    width: 32px;
    height: 32px;
  }
  
  .logout-btn span {
    display: none;
  }
  
  .logout-btn {
    padding: 8px;
    font-size: 14px;
  }
  
  /* Sidebar Responsive */
  .sidebar {
    position: fixed;
    top: 60px;
    left: -240px;
    width: 240px;
    height: calc(100% - 60px);
    transition: left 0.3s ease;
    z-index: 999;
    background: linear-gradient(180deg, var(--hu-green), #007530);
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
  }
  
  .sidebar.mobile-open {
    left: 0;
  }
  
  .sidebar.collapsed {
    left: -240px;
    width: 240px;
  }
  
  /* Main Content */
  .main-content {
    margin-left: 0 !important;
    padding: 12px;
    margin-bottom: 60px;
  }
  
  body.sidebar-collapsed .main-content {
    margin-left: 0 !important;
  }
  
  /* Footer Responsive (already included above) */
  
  /* Profile Modal Responsive */
  .profile-content {
    width: 90%;
    max-width: 300px;
    margin: 0 auto;
  }
  
  /* Overlay Responsive */
  .overlay-content {
    width: 95%;
    height: 95%;
  }
}

/* Mobile Phones (480px iyo ka hooseeyo) */
@media (max-width: 480px) {
  .hu-header {
    padding: 6px 10px;
    height: 55px;
  }
  
  .logo-circle {
    padding: 3px;
  }
  
  .hu-logo {
    height: 30px;
    width: 30px;
  }
  
  .menu-btn {
    width: 32px;
    height: 32px;
    font-size: 18px;
  }
  
  .logout-btn {
    padding: 6px 8px;
    font-size: 12px;
  }
  
  .sidebar {
    width: 220px;
    left: -220px;
    top: 55px;
    height: calc(100% - 55px);
  }
  
  .sidebar a {
    padding: 8px 12px;
    font-size: 14px;
  }
  
  .sidebar .submenu a {
    padding-left: 30px;
  }
  
  .main-content {
    padding: 10px;
    margin-bottom: 55px;
  }
  

/* Large Devices (768px - 1024px) */
@media (min-width: 769px) and (max-width: 1024px) {
  .sidebar {
    width: 200px;
  }
  
  body.sidebar-collapsed .sidebar {
    width: 60px;
  }
  
  .main-content {
    margin-left: 200px;
  }
  
  body.sidebar-collapsed .main-content {
    margin-left: 60px;
  }
  



/* Landscape Mode for Mobile */


/* High Resolution Screens (Retina) */
@media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
  .hu-logo {
    background-image: url('https://hu.edu.so/static/img/public/hu_official_logo_without_text-and_bg_png@2x.png');
    background-size: contain;
  }
}

/* Touch Device Optimizations */
@media (hover: none) and (pointer: coarse) {
  .sidebar a,
  .menu-toggle,
  .user-info,
  .logout-btn,
  .profile-link,
  .refresh-btn,
  .social-link {
    min-height: 44px;
    min-width: 44px;
  }
  
  .sidebar a {
    padding: 12px 16px;
  }
  
  .menu-toggle {
    padding: 12px 16px;
  }
  
  .logout-btn {
    padding: 12px 16px;
  }
  
  .sidebar {
    -webkit-overflow-scrolling: touch;
  }
}

body.sidebar-collapsed .sidebar {
  width: 60px;
}
</style>
</head>
<body>

<header class="hu-header">
  <div class="hu-left">
    <button id="sidebarToggle" class="menu-btn" aria-label="Toggle Sidebar">
      <i class="fa-solid fa-bars" id="toggleIcon"></i>
    </button>
    <div class="logo-circle">
      <img src="https://hu.edu.so/static/img/public/hu_official_logo_without_text-and_bg_png.png" class="hu-logo" alt="HU Logo">
    </div>
    <span class="hu-title">Hormuud University</span>
  </div>
  <div class="hu-right">
    <div class="user-info" id="profileOpen">
      <img src="<?= $photo ?>" alt="Profile" class="user-photo" id="userProfilePhoto">
      <div>
        <span style="font-weight:600;"><?= $display_name ?></span><br>
        <small><?= ucfirst(str_replace('_', ' ', $role)) ?></small>
      </div>
    </div>
    <a href="../logout.php" class="logout-btn">
      <i class="fa-solid fa-right-from-bracket"></i>
      <span>Logout</span>
    </a>
  </div>
</header>

<!-- ✅ SIDEBAR NAVIGATION -->
<nav class="sidebar" id="sidebar">
  <ul>
    <?php if ($role === 'super_admin'): ?>
      <?php if(hasPermission('dashboard')): ?>
      <li><a href="../super_admin/dashboard.php" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
      <?php endif; ?>
      
      <!-- University -->
      <?php if(hasPermission('university_menu') || hasPermission('campus') || hasPermission('faculty') || hasPermission('department') || hasPermission('rooms') || hasPermission('room_allocation')): ?>
      <li>
        <div class="menu-toggle" data-target="uniSuper"><span><i class="fa-solid fa-university"></i> University</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="uniSuper">
          <?php if(hasPermission('campus')): ?>
          <li><a href="../super_admin/campus.php"><i class="fa-solid fa-building-columns"></i> Campus</a></li>
          <?php endif; ?>
          <?php if(hasPermission('faculty')): ?>
          <li><a href="../super_admin/faculty.php"><i class="fa-solid fa-graduation-cap"></i> Faculty</a></li>
          <?php endif; ?>
          <?php if(hasPermission('department')): ?>
          <li><a href="../super_admin/department.php"><i class="fa-solid fa-building"></i> Department</a></li>
          <?php endif; ?>
          <?php if(hasPermission('rooms')): ?>
          <li><a href="../super_admin/rooms.php"><i class="fa-solid fa-door-open"></i> Rooms</a></li>
          <?php endif; ?>
          <?php if(hasPermission('room_allocation')): ?>
          <li><a href="../super_admin/room_allocations.php"><i class="fa-solid fa-clipboard-list"></i> Room Allocation</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <!-- Academic -->
      <?php if(hasPermission('academic_menu') || hasPermission('academic_years') || hasPermission('academic_terms') || hasPermission('semesters') || hasPermission('programs') || hasPermission('classes') || hasPermission('courses') || hasPermission('recourse_assign') || hasPermission('student_enroll') || hasPermission('timetable') || hasPermission('promotion') || hasPermission('attendance')): ?>
      <li>
        <div class="menu-toggle" data-target="acadSuper"><span><i class="fa-solid fa-book-open"></i> Academic</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="acadSuper">
          <?php if(hasPermission('academic_years')): ?>
          <li><a href="../super_admin/academic_years.php"><i class="fa-regular fa-calendar"></i> Academic Years</a></li>
          <?php endif; ?>
          <?php if(hasPermission('academic_terms')): ?>
          <li><a href="../super_admin/academic_terms.php"><i class="fa-solid fa-calendar-week"></i> Academic Terms</a></li>
          <?php endif; ?>
          <?php if(hasPermission('semesters')): ?>
          <li><a href="../super_admin/semesters.php"><i class="fa-solid fa-book"></i> Semesters</a></li>
          <?php endif; ?>
          <?php if(hasPermission('programs')): ?>
          <li><a href="../super_admin/programsa.php"><i class="fa-solid fa-layer-group"></i> Programs</a></li>
          <?php endif; ?>
          <?php if(hasPermission('classes')): ?>
          <li><a href="../super_admin/classes.php"> <i class="fa-solid fa-chalkboard"></i> Classes </a></li>
          <?php endif; ?>
          <?php if(hasPermission('courses')): ?>
          <li><a href="../super_admin/courses.php"><i class="fa-solid fa-bookmark"></i> Courses</a></li>
          <?php endif; ?>
          <?php if(hasPermission('recourse_assign')): ?>
          <li><a href="../super_admin/recourse_assign.php"><i class="fa-solid fa-repeat"></i> Recourse Assign</a></li>
          <?php endif; ?>
          <?php if(hasPermission('student_enroll')): ?>
          <li><a href="../super_admin/student_enroll.php"><i class="fa-solid fa-user-plus"></i> Student Enroll</a></li>
          <?php endif; ?>
          <?php if(hasPermission('timetable')): ?>
          <li><a href="../super_admin/timetable.php"><i class="fa-solid fa-table"></i> Timetable</a></li>
          <?php endif; ?>
          <?php if(hasPermission('promotion')): ?>
          <li><a href="../super_admin/promotion_history.php"><i class="fa-solid fa-arrow-up"></i> Promotion</a></li>
          <?php endif; ?>
          <?php if(hasPermission('attendance')): ?>
          <li><a href="../super_admin/attendance.php"><i class="fa-solid fa-clock"></i> Attendance</a></li>
<li>
    <a href="../super_admin/attendance_permission.php">
        <i class="fa-solid fa-user-clock"></i> Attendance Permission
    </a>
</li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <!-- People -->
      <?php if(hasPermission('people_menu') || hasPermission('teachers') || hasPermission('students') || hasPermission('parents') || hasPermission('users')): ?>
      <li>
        <div class="menu-toggle" data-target="peopleSuper"><span><i class="fa-solid fa-users"></i> People</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="peopleSuper">
          <?php if(hasPermission('teachers')): ?>
          <li><a href="../super_admin/teachers.php"><i class="fa-solid fa-user-tie"></i> Teachers</a></li>
          <?php endif; ?>
          <?php if(hasPermission('students')): ?>
          <li><a href="../super_admin/students.php"><i class="fa-solid fa-user-graduate"></i> Students</a></li>
          <?php endif; ?>
          <?php if(hasPermission('parents')): ?>
          <li><a href="../super_admin/parents.php"><i class="fa-solid fa-user-group"></i> Parents</a></li>
          <?php endif; ?>
          <?php if(hasPermission('users')): ?>
          <li><a href="../super_admin/users.php"><i class="fa-solid fa-user-shield"></i> User Accounts</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <!-- Reports -->
      <?php if(hasPermission('reports_menu') || hasPermission('attendance_report') || hasPermission('promotion_report') || hasPermission('reports_overview')): ?>
      <li>
        <div class="menu-toggle" data-target="reportSuper"><span><i class="fa-solid fa-chart-line"></i> Reports</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="reportSuper">
          <?php if(hasPermission('attendance_report')): ?>
          <li><a href="../super_admin/attendance_report.php"><i class="fa-solid fa-clock"></i> Attendance Report</a></li>
          <?php endif; ?>
          <?php if(hasPermission('promotion_report')): ?>
          <li><a href="../super_admin/promotion_report.php"><i class="fa-solid fa-arrow-trend-up"></i> Promotions</a></li>
          <?php endif; ?>
          <?php if(hasPermission('reports_overview')): ?>
          <li><a href="../super_admin/reports.php"><i class="fa-solid fa-chart-pie"></i> Reports Overview</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <?php if(hasPermission('announcements')): ?>
      <li><a href="../super_admin/announcements.php"><i class="fa-solid fa-bullhorn"></i><span>Announcements</span></a></li>
      <?php endif; ?>
      
      <!-- Settings -->
      <?php if(hasPermission('settings_menu') || hasPermission('notifications') || hasPermission('audit_logs')): ?>
      <li>
        <div class="menu-toggle" data-target="settingSuper"><span><i class="fa-solid fa-gear"></i> Settings</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="settingSuper">
          <?php if(hasPermission('notifications')): ?>
          <li><a href="../super_admin/notification.php"><i class="fa-solid fa-bell"></i> Notifications</a></li>
          <?php endif; ?>
          <?php if(hasPermission('audit_logs')): ?>
          <li><a href="../super_admin/audit_logs.php"><i class="fa-solid fa-file-invoice"></i> Audit Logs</a></li>
          <!-- <li><a href="../super_admin/Permission.php"><i class="fa-solid fa-user-shield"></i> User Permissions</a></li> -->
<?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
    <?php elseif ($role === 'campus_admin'): ?>
      <!-- Campus Admin menu -->
      <?php if(hasPermission('dashboard')): ?>
      <li><a href="../campus_admin/dashboard.php" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
      <?php endif; ?>
      
      <!-- University -->
      <?php if(hasPermission('university_menu') || hasPermission('faculty') || hasPermission('department') || hasPermission('rooms') || hasPermission('room_allocation')): ?>
      <li>
        <div class="menu-toggle" data-target="uniSuper"><span><i class="fa-solid fa-university"></i> University</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="uniSuper">
          <?php if(hasPermission('faculty')): ?>
          <li><a href="../campus_admin/faculty.php"><i class="fa-solid fa-graduation-cap"></i> Faculty</a></li>
          <?php endif; ?>
          <?php if(hasPermission('department')): ?>
          <li><a href="../campus_admin/department.php"><i class="fa-solid fa-building"></i> Department</a></li>
          <?php endif; ?>
          <?php if(hasPermission('rooms')): ?>
          <li><a href="../campus_admin/rooms.php"><i class="fa-solid fa-door-open"></i> Rooms</a></li>
          <?php endif; ?>
          <?php if(hasPermission('room_allocation')): ?>
          <li><a href="../campus_admin/room_allocations.php"><i class="fa-solid fa-clipboard-list"></i> Room Allocation</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <!-- Academic -->
      <?php if(hasPermission('academic_menu') || hasPermission('academic_years') || hasPermission('academic_terms') || hasPermission('semesters') || hasPermission('programs') || hasPermission('classes') || hasPermission('courses') || hasPermission('student_enroll') || hasPermission('timetable') || hasPermission('promotion') || hasPermission('attendance')): ?>
      <li>
        <div class="menu-toggle" data-target="acadSuper"><span><i class="fa-solid fa-book-open"></i> Academic</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="acadSuper">
          <?php if(hasPermission('academic_years')): ?>
          <li><a href="../campus_admin/academic_years.php"><i class="fa-regular fa-calendar"></i> Academic Years</a></li>
          <?php endif; ?>
          <?php if(hasPermission('academic_terms')): ?>
          <li><a href="../campus_admin/academic_terms.php"><i class="fa-solid fa-calendar-week"></i> Academic Terms</a></li>
          <?php endif; ?>
          <?php if(hasPermission('semesters')): ?>
          <li><a href="../campus_admin/semesters.php"><i class="fa-solid fa-book"></i> Semesters</a></li>
          <?php endif; ?>
          <?php if(hasPermission('programs')): ?>
          <li><a href="../campus_admin/programsa.php"><i class="fa-solid fa-layer-group"></i> Programs</a></li>
          <?php endif; ?>
          <?php if(hasPermission('classes')): ?>
          <li><a href="../campus_admin/classes.php"> <i class="fa-solid fa-chalkboard"></i> Classes </a></li>
          <?php endif; ?>
          <?php if(hasPermission('courses')): ?>
          <li><a href="../campus_admin/courses.php"><i class="fa-solid fa-bookmark"></i> Courses</a></li>
          <?php endif; ?>
          <?php if(hasPermission('recourse_assign')): ?>
          <li><a href="../campus_admin/recourse_assign.php"><i class="fa-solid fa-repeat"></i> Recourse Assign</a></li>
          <?php endif; ?>
          <?php if(hasPermission('student_enroll')): ?>
          <li><a href="../campus_admin/student_enroll.php"><i class="fa-solid fa-user-plus"></i> Student Enroll</a></li>
          <?php endif; ?>
          <?php if(hasPermission('timetable')): ?>
          <li><a href="../campus_admin/timetable.php"><i class="fa-solid fa-table"></i> Timetable</a></li>
          <?php endif; ?>
          <?php if(hasPermission('promotion')): ?>
          <li><a href="../campus_admin/promotion_history.php"><i class="fa-solid fa-arrow-up"></i> Promotion</a></li>
          <?php endif; ?>
          <?php if(hasPermission('attendance')): ?>
          <li><a href="../campus_admin/attendance.php"><i class="fa-solid fa-clock"></i> Attendance</a></li>
          <?php endif; ?>
           <?php if(hasPermission('attendance')): ?>
<li>
    <a href="../campus_admin/attendance_permission.php">
        <i class="fa-solid fa-user-clock"></i> Attendance Permission
    </a>
</li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <!-- People -->
      <?php if(hasPermission('people_menu') || hasPermission('teachers') || hasPermission('students') || hasPermission('parents') || hasPermission('users')): ?>
      <li>
        <div class="menu-toggle" data-target="peopleSuper"><span><i class="fa-solid fa-users"></i> People</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="peopleSuper">
          <?php if(hasPermission('teachers')): ?>
          <li><a href="../campus_admin/teachers.php"><i class="fa-solid fa-user-tie"></i> Teachers</a></li>
          <?php endif; ?>
          <?php if(hasPermission('students')): ?>
          <li><a href="../campus_admin/students.php"><i class="fa-solid fa-user-graduate"></i> Students</a></li>
          <?php endif; ?>
          <?php if(hasPermission('parents')): ?>
          <li><a href="../campus_admin/parents.php"><i class="fa-solid fa-user-group"></i> Parents</a></li>
          <?php endif; ?>
          <?php if(hasPermission('users')): ?>
          <li><a href="../campus_admin/users.php"><i class="fa-solid fa-user-shield"></i> User Accounts</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <!-- Reports -->
      <?php if(hasPermission('reports_menu') || hasPermission('attendance_report') || hasPermission('promotion_report') || hasPermission('reports_overview')): ?>
      <li>
        <div class="menu-toggle" data-target="reportSuper"><span><i class="fa-solid fa-chart-line"></i> Reports</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="reportSuper">
          <?php if(hasPermission('attendance_report')): ?>
          <li><a href="../campus_admin/attendance_report.php"><i class="fa-solid fa-clock"></i> Attendance Report</a></li>
          <?php endif; ?>
          <?php if(hasPermission('promotion_report')): ?>
          <li><a href="../campus_admin/promotion_report.php"><i class="fa-solid fa-arrow-trend-up"></i> Promotions</a></li>
          <?php endif; ?>
          <?php if(hasPermission('reports_overview')): ?>
          <li><a href="../campus_admin/reports.php"><i class="fa-solid fa-chart-pie"></i> Reports Overview</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <?php if(hasPermission('announcements')): ?>
      <li><a href="../campus_admin/announcements.php"><i class="fa-solid fa-bullhorn"></i><span>Announcements</span></a></li>
      <?php endif; ?>
      
      <!-- Settings -->
      <?php if(hasPermission('settings_menu') || hasPermission('notifications') || hasPermission('audit_logs')): ?>
      <li>
        <div class="menu-toggle" data-target="settingSuper"><span><i class="fa-solid fa-gear"></i> Settings</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="settingSuper">
          <?php if(hasPermission('notifications')): ?>
          <li><a href="../campus_admin/notification.php"><i class="fa-solid fa-bell"></i> Notifications</a></li>
          <?php endif; ?>
          <?php if(hasPermission('audit_logs')): ?>
          <li><a href="../campus_admin/audit_logs.php"><i class="fa-solid fa-file-invoice"></i> Audit Logs</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
    <?php elseif ($role === 'faculty_admin'): ?>
      <!-- Faculty Admin menu -->
      <?php if(hasPermission('dashboard')): ?>
      <li><a href="../faculty_admin/dashboard.php" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
      <?php endif; ?>
      
      <!-- University -->
      <?php if(hasPermission('university_menu') || hasPermission('department') || hasPermission('rooms') || hasPermission('room_allocation')): ?>
      <li>
        <div class="menu-toggle" data-target="uniSuper"><span><i class="fa-solid fa-university"></i> University</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="uniSuper">
          <?php if(hasPermission('department')): ?>
          <li><a href="../faculty_admin/department.php"><i class="fa-solid fa-building"></i> Department</a></li>
          <?php endif; ?>
          <?php if(hasPermission('rooms')): ?>
          <li><a href="../faculty_admin/rooms.php"><i class="fa-solid fa-door-open"></i> Rooms</a></li>
          <?php endif; ?>
          <?php if(hasPermission('room_allocation')): ?>
          <li><a href="../faculty_admin/room_allocations.php"><i class="fa-solid fa-clipboard-list"></i> Room Allocation</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <!-- Academic -->
      <?php if(hasPermission('academic_menu') || hasPermission('academic_years') || hasPermission('academic_terms') || hasPermission('semesters') || hasPermission('programs') || hasPermission('classes') || hasPermission('courses') || hasPermission('student_enroll') || hasPermission('timetable') || hasPermission('promotion') || hasPermission('attendance')): ?>
      <li>
        <div class="menu-toggle" data-target="acadSuper"><span><i class="fa-solid fa-book-open"></i> Academic</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="acadSuper">
          <?php if(hasPermission('academic_years')): ?>
          <li><a href="../faculty_admin/academic_years.php"><i class="fa-regular fa-calendar"></i> Academic Years</a></li>
          <?php endif; ?>
          <?php if(hasPermission('academic_terms')): ?>
          <li><a href="../faculty_admin/academic_terms.php"><i class="fa-solid fa-calendar-week"></i> Academic Terms</a></li>
          <?php endif; ?>
          <?php if(hasPermission('semesters')): ?>
          <li><a href="../faculty_admin/semesters.php"><i class="fa-solid fa-book"></i> Semesters</a></li>
          <?php endif; ?>
          <?php if(hasPermission('programs')): ?>
          <li><a href="../faculty_admin/programsa.php"><i class="fa-solid fa-layer-group"></i> Programs</a></li>
          <?php endif; ?>
          <?php if(hasPermission('classes')): ?>
          <li><a href="../faculty_admin/classes.php"> <i class="fa-solid fa-chalkboard"></i> Classes </a></li>
          <?php endif; ?>
          <?php if(hasPermission('courses')): ?>
          <li><a href="../faculty_admin/courses.php"><i class="fa-solid fa-bookmark"></i> Courses</a></li>
          <?php endif; ?>
          <?php if(hasPermission('recourse_assign')): ?>
          <li><a href="../faculty_admin/recourse_assign.php"><i class="fa-solid fa-repeat"></i> Recourse Assign</a></li>
          <?php endif; ?>
          <?php if(hasPermission('student_enroll')): ?>
          <li><a href="../faculty_admin/student_enrol.php"><i class="fa-solid fa-user-plus"></i> Student Enroll</a></li>
          <?php endif; ?>
          <?php if(hasPermission('timetable')): ?>
          <li><a href="../faculty_admin/timetable.php"><i class="fa-solid fa-table"></i> Timetable</a></li>
          <?php endif; ?>
          <?php if(hasPermission('promotion')): ?>
          <li><a href="../faculty_admin/promotion_history.php"><i class="fa-solid fa-arrow-up"></i> Promotion</a></li>
          <?php endif; ?>
          <?php if(hasPermission('attendance')): ?>
          <li><a href="../faculty_admin/attendance.php"><i class="fa-solid fa-clock"></i> Attendance</a></li>
          <?php endif; ?>
          <?php if(hasPermission('attendance')): ?>
    <li>
        <a href="../faculty_admin/attendance_permission.php">
            <i class="fa-solid fa-user-clock"></i> Attendance Permissions
        </a>
    </li>
<?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <!-- People -->
      <?php if(hasPermission('people_menu') || hasPermission('teachers') || hasPermission('students') || hasPermission('parents') || hasPermission('users')): ?>
      <li>
        <div class="menu-toggle" data-target="peopleSuper"><span><i class="fa-solid fa-users"></i> People</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="peopleSuper">
          <?php if(hasPermission('teachers')): ?>
          <li><a href="../faculty_admin/teachers.php"><i class="fa-solid fa-user-tie"></i> Teachers</a></li>
          <?php endif; ?>
          <?php if(hasPermission('students')): ?>
          <li><a href="../faculty_admin/students.php"><i class="fa-solid fa-user-graduate"></i> Students</a></li>
          <?php endif; ?>
          <?php if(hasPermission('parents')): ?>
          <li><a href="../faculty_admin/parents.php"><i class="fa-solid fa-user-group"></i> Parents</a></li>
          <?php endif; ?>
          <?php if(hasPermission('users')): ?>
          <li><a href="../faculty_admin/users.php"><i class="fa-solid fa-user-shield"></i> User Accounts</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <!-- Reports -->
      <?php if(hasPermission('reports_menu') || hasPermission('attendance_report') || hasPermission('promotion_report') || hasPermission('reports_overview')): ?>
      <li>
        <div class="menu-toggle" data-target="reportSuper"><span><i class="fa-solid fa-chart-line"></i> Reports</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="reportSuper">
          <?php if(hasPermission('attendance_report')): ?>
          <li><a href="../faculty_admin/attendance_report.php"><i class="fa-solid fa-clock"></i> Attendance Report</a></li>
          <?php endif; ?>
          <?php if(hasPermission('promotion_report')): ?>
          <li><a href="../faculty_admin/promotion_report.php"><i class="fa-solid fa-arrow-trend-up"></i> Promotions</a></li>
          <?php endif; ?>
          <?php if(hasPermission('reports_overview')): ?>
          <li><a href="../faculty_admin/reports.php"><i class="fa-solid fa-chart-pie"></i> Reports Overview</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <?php if(hasPermission('announcements')): ?>
      <li><a href="../faculty_admin/announcements.php"><i class="fa-solid fa-bullhorn"></i><span>Announcements</span></a></li>
      <?php endif; ?>
      
      <!-- Settings -->
      <?php if(hasPermission('settings_menu') || hasPermission('notifications') || hasPermission('audit_logs')): ?>
      <li>
        <div class="menu-toggle" data-target="settingSuper"><span><i class="fa-solid fa-gear"></i> Settings</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="settingSuper">
          <?php if(hasPermission('notifications')): ?>
          <li><a href="../faculty_admin/notification.php"><i class="fa-solid fa-bell"></i> Notifications</a></li>
          <?php endif; ?>
          <?php if(hasPermission('audit_logs')): ?>
          <li><a href="../faculty_admin/audit_logs.php"><i class="fa-solid fa-file-invoice"></i> Audit Logs</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
    <?php elseif ($role === 'department_admin'): ?>
      <!-- Department Admin menu -->
      <?php if(hasPermission('dashboard')): ?>
      <li><a href="../department_admin/dashboard.php" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
      <?php endif; ?>
      
      <!-- University -->
      <?php if(hasPermission('university_menu') || hasPermission('rooms') || hasPermission('room_allocation')): ?>
      <li>
        <div class="menu-toggle" data-target="uniSuper"><span><i class="fa-solid fa-university"></i> University</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="uniSuper">
          <?php if(hasPermission('rooms')): ?>
          <li><a href="../department_admin/rooms.php"><i class="fa-solid fa-door-open"></i> Rooms</a></li>
          <?php endif; ?>
          <?php if(hasPermission('room_allocation')): ?>
          <li><a href="../department_admin/room_allocations.php"><i class="fa-solid fa-clipboard-list"></i> Room Allocation</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <!-- Academic -->
      <?php if(hasPermission('academic_menu') || hasPermission('academic_years') || hasPermission('academic_terms') || hasPermission('semesters') || hasPermission('programs') || hasPermission('classes') || hasPermission('courses') || hasPermission('student_enroll') || hasPermission('timetable') || hasPermission('promotion') || hasPermission('attendance')): ?>
      <li>
        <div class="menu-toggle" data-target="acadSuper"><span><i class="fa-solid fa-book-open"></i> Academic</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="acadSuper">
          <?php if(hasPermission('academic_years')): ?>
          <li><a href="../department_admin/academic_year.php"><i class="fa-regular fa-calendar"></i> Academic Years</a></li>
          <?php endif; ?>
          <?php if(hasPermission('academic_terms')): ?>
          <li><a href="../department_admin/academic_term.php"><i class="fa-solid fa-calendar-week"></i> Academic Terms</a></li>
          <?php endif; ?>
          <?php if(hasPermission('semesters')): ?>
          <li><a href="../department_admin/semester.php"><i class="fa-solid fa-book"></i> Semesters</a></li>
          <?php endif; ?>
          <?php if(hasPermission('programs')): ?>
          <li><a href="../department_admin/programsa.php"><i class="fa-solid fa-layer-group"></i> Programs</a></li>
          <?php endif; ?>
          <?php if(hasPermission('classes')): ?>
          <li><a href="../department_admin/classes.php"> <i class="fa-solid fa-chalkboard"></i> Classes </a></li>
          <?php endif; ?>
          <?php if(hasPermission('courses')): ?>
          <li><a href="../department_admin/courses.php"><i class="fa-solid fa-bookmark"></i> Courses</a></li>
          <?php endif; ?>
          <?php if(hasPermission('recourse_assign')): ?>
          <li><a href="../department_admin/recourse_assign.php"><i class="fa-solid fa-repeat"></i> Recourse Assign</a></li>
          <?php endif; ?>
          <?php if(hasPermission('student_enroll')): ?>
          <li><a href="../department_admin/student_enrol.php"><i class="fa-solid fa-user-plus"></i> Student Enroll</a></li>
          <?php endif; ?>
          <?php if(hasPermission('timetable')): ?>
          <li><a href="../department_admin/timetable.php"><i class="fa-solid fa-table"></i> Timetable</a></li>
          <?php endif; ?>
          <?php if(hasPermission('promotion')): ?>
          <li><a href="../department_admin/promotion_history.php"><i class="fa-solid fa-arrow-up"></i> Promotion</a></li>
          <?php endif; ?>
          <?php if(hasPermission('attendance')): ?>
          <li><a href="../department_admin/attendance.php"><i class="fa-solid fa-clock"></i> Attendance</a></li>
          <?php endif; ?>
               <?php if(hasPermission('attendance')): ?>
<li>
    <a href="../department_admin/attendance_permission.php">
        <i class="fa-solid fa-user-clock"></i> Attendance Permission
    </a>
</li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <!-- People -->
      <?php if(hasPermission('people_menu') || hasPermission('teachers') || hasPermission('students') || hasPermission('parents') || hasPermission('users')): ?>
      <li>
        <div class="menu-toggle" data-target="peopleSuper"><span><i class="fa-solid fa-users"></i> People</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="peopleSuper">
          <?php if(hasPermission('teachers')): ?>
          <li><a href="../department_admin/teachers.php"><i class="fa-solid fa-user-tie"></i> Teachers</a></li>
          <?php endif; ?>
          <?php if(hasPermission('students')): ?>
          <li><a href="../department_admin/students.php"><i class="fa-solid fa-user-graduate"></i> Students</a></li>
          <?php endif; ?>
          <?php if(hasPermission('parents')): ?>
          <li><a href="../department_admin/parents.php"><i class="fa-solid fa-user-group"></i> Parents</a></li>
          <?php endif; ?>
          <?php if(hasPermission('users')): ?>
          <li><a href="../department_admin/users.php"><i class="fa-solid fa-user-shield"></i> User Accounts</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <!-- Reports -->
      <?php if(hasPermission('reports_menu') || hasPermission('attendance_report') || hasPermission('promotion_report') || hasPermission('reports_overview')): ?>
      <li>
        <div class="menu-toggle" data-target="reportSuper"><span><i class="fa-solid fa-chart-line"></i> Reports</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="reportSuper">
          <?php if(hasPermission('attendance_report')): ?>
          <li><a href="../department_admin/attendance_report.php"><i class="fa-solid fa-clock"></i> Attendance Report</a></li>
          <?php endif; ?>
          <?php if(hasPermission('promotion_report')): ?>
          <li><a href="../department_admin/promotion_report.php"><i class="fa-solid fa-arrow-trend-up"></i> Promotions</a></li>
          <?php endif; ?>
          <?php if(hasPermission('reports_overview')): ?>
          <li><a href="../department_admin/reports.php"><i class="fa-solid fa-chart-pie"></i> Reports Overview</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
      <?php if(hasPermission('announcements')): ?>
      <li><a href="../department_admin/announcements.php"><i class="fa-solid fa-bullhorn"></i><span>Announcements</span></a></li>
      <?php endif; ?>
      
      <!-- Settings -->
       <?php if(hasPermission('settings_menu') || hasPermission('notifications') || hasPermission('audit_logs')): ?>
      <li>
        <div class="menu-toggle" data-target="settingSuper"><span><i class="fa-solid fa-gear"></i> Settings</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="settingSuper">
          <?php if(hasPermission('notifications')): ?>
          <li><a href="../department_admin/notification.php"><i class="fa-solid fa-bell"></i> Notifications</a></li>
          <?php endif; ?>
          <?php if(hasPermission('audit_logs')): ?>
          <li><a href="../department_admin/audit_logs.php"><i class="fa-solid fa-file-invoice"></i> Audit Logs</a></li>
          <?php endif; ?>
        </ul>
      </li>
      <?php endif; ?>
      
    <?php elseif ($role === 'teacher'): ?>
    <!-- Teacher menu -->
    
    <!-- Dashboard -->
    <?php if(hasPermission('dashboard')): ?>
    <li><a href="../teacher/dashboard.php" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
    <?php endif; ?>
    
    <!-- Academic -->
    <?php if(hasPermission('academic_menu') || hasPermission('courses') || hasPermission('timetable') || hasPermission('attendance') || hasPermission('exams') || hasPermission('assignments')): ?>
    <li>
        <div class="menu-toggle" data-target="academicTeacher"><span><i class="fa-solid fa-book-open"></i> Academic</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="academicTeacher">
            <?php if(hasPermission('courses')): ?>
            <li><a href="../teacher/courses.php"><i class="fa-solid fa-bookmark"></i> Courses</a></li>
            <?php endif; ?>
            <?php if(hasPermission('timetable')): ?>
            <li><a href="../teacher/timetable.php"><i class="fa-solid fa-table"></i> Timetable</a></li>
            <?php endif; ?>
            <?php if(hasPermission('attendance')): ?>
            <li><a href="../teacher/attendance.php"><i class="fa-solid fa-clock"></i> Attendance</a></li>
            <?php endif; ?>
            <?php if(hasPermission('attendance_permission')): ?>
            <li><a href="../teacher/attendance_permission.php"><i class="fa-solid fa-user-clock"></i> Attendance Permission</a></li>
            <?php endif; ?>
           
        </ul>
    </li>
    <?php endif; ?>
    
    <!-- People (if teacher needs to view students/parents) -->
    <?php if(hasPermission('people_menu') || hasPermission('view_students') || hasPermission('view_parents')): ?>
    <li>
        <div class="menu-toggle" data-target="peopleTeacher"><span><i class="fa-solid fa-users"></i> People</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="peopleTeacher">
            <?php if(hasPermission('view_students')): ?>
            <li><a href="../teacher/students.php"><i class="fa-solid fa-user-graduate"></i> Students</a></li>
            <?php endif; ?>
           
        </ul>
    </li>
    <?php endif; ?>
    
    <!-- Reports -->
    <?php if(hasPermission('reports_menu') || hasPermission('attendance_report') || hasPermission('grade_report') || hasPermission('student_performance')): ?>
    <li>
        <div class="menu-toggle" data-target="reportsTeacher"><span><i class="fa-solid fa-chart-line"></i> Reports</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="reportsTeacher">
            <?php if(hasPermission('attendance_report')): ?>
            <li><a href="../teacher/attendance_report.php"><i class="fa-solid fa-clock"></i> Attendance Report</a></li>
            <?php endif; ?>
           
            <?php if(hasPermission('reports_overview')): ?>
            <li><a href="../teacher/reports.php"><i class="fa-solid fa-chart-pie"></i> Reports Overview</a></li>
            <?php endif; ?>
        </ul>
    </li>
    <?php endif; ?>
    
    <!-- Announcements -->
    <?php if(hasPermission('announcements')): ?>
    <li><a href="../teacher/announcements.php"><i class="fa-solid fa-bullhorn"></i><span>Announcements</span></a></li>
    <?php endif; ?>
    
    <!-- Settings -->
    <?php if(hasPermission('settings_menu') || hasPermission('profile_settings') || hasPermission('notifications')): ?>
    <li>
        <div class="menu-toggle" data-target="settingsTeacher"><span><i class="fa-solid fa-gear"></i> Settings</span><i class="fa-solid fa-chevron-right"></i></div>
        <ul class="submenu" id="settingsTeacher">
           
            <?php if(hasPermission('notifications')): ?>
            <li><a href="../teacher/notification.php"><i class="fa-solid fa-bell"></i> Notifications</a></li>
            <?php endif; ?>
          
        </ul>
    </li>
    <?php endif; ?>
    <?php elseif ($role === 'student'): ?>
      <?php if(hasPermission('dashboard')): ?>
      <li><a href="../student/dashboard.php" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
      <?php endif; ?>
      <?php if(hasPermission('courses')): ?>
      <li><a href="../student/courses.php"><i class="fa-solid fa-bookmark"></i><span>Courses</span></a></li>
      <?php endif; ?>
      <?php if(hasPermission('timetable')): ?>
      <li><a href="../student/timetable.php"><i class="fa-solid fa-table"></i><span>Timetable</span></a></li>
      <?php endif; ?>
      <?php if(hasPermission('attendance')): ?>
      <li><a href="../student/attendance.php"><i class="fa-solid fa-clock"></i><span>Attendance</span></a></li>
      <?php endif; ?>
      <?php if(hasPermission('announcements')): ?>
      <li><a href="../student/announcements.php"><i class="fa-solid fa-bullhorn"></i><span>Announcements</span></a></li>
      <?php endif; ?>
    <?php elseif ($role === 'parent'): ?>
      <?php if(hasPermission('dashboard')): ?>
      <li><a href="../parent/dashboard.php" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>"><i class="fa-solid fa-house"></i><span>Dashboard</span></a></li>
      <?php endif; ?>
      <?php if(hasPermission('student_progress')): ?>
      <li><a href="../parent/student_progress.php"><i class="fa-solid fa-user-graduate"></i><span>Student Progress</span></a></li>
      <?php endif; ?>
      <?php if(hasPermission('attendance')): ?>
      <li><a href="../parent/attendance.php"><i class="fa-solid fa-clock"></i><span>Attendance</span></a></li>
      <?php endif; ?>
      <?php if(hasPermission('reports')): ?>
      <li><a href="../parent/reports.php"><i class="fa-solid fa-chart-pie"></i><span>Reports</span></a></li>
      <?php endif; ?>
      <?php if(hasPermission('announcements')): ?>
      <li><a href="../parent/announcements.php"><i class="fa-solid fa-bullhorn"></i><span>Announcements</span></a></li>
      <?php endif; ?>
    <?php else: ?>
      <li><a href="#"><i class="fa-solid fa-ban"></i><span>No Menu Assigned</span></a></li>
    <?php endif; ?>
  </ul>
</nav>



<!-- ✅ PROFILE MODAL -->
<div class="profile-modal" id="profileModal">
  <div class="profile-content">
    <span class="close-profile" id="closeProfile">&times;</span>
    <img src="<?= $photo ?>" alt="Profile" class="profile-avatar" id="modalProfilePhoto">
    <div class="profile-info"><h2 id="modalDisplayName"><?= $display_name ?></h2></div>
    <?php if ($photo == "../upload/profiles/default.png") : ?>
      <div class="no-photo-message"><i class="fa-solid fa-exclamation-triangle"></i> No profile photo uploaded.</div>
    <?php endif; ?>
    <div class="profile-links">
      <button class="profile-link" id="viewProfileBtn"><i class="fa-solid fa-user"></i> View Profile</button>
      <button class="profile-link" id="changePasswordBtn"><i class="fa-solid fa-lock"></i> Change Password</button>
    </div>
  </div>
</div>

<div class="overlay" id="overlayView">
  <div class="overlay-content"><span class="close-overlay" data-close="overlayView">&times;</span><iframe src="../config/view_profile.php" class="overlay-frame"></iframe></div>
</div>
<div class="overlay" id="overlayPassword">
  <div class="overlay-content"><span class="close-overlay" data-close="overlayPassword">&times;</span><iframe src="../config/change_password.php" class="overlay-frame"></iframe></div>
</div>

<script>
// ✅ Mobile navigation
let isMobile = window.innerWidth <= 768;
const sidebar = document.getElementById("sidebar");
const toggleBtn = document.getElementById("sidebarToggle");
const toggleIcon = document.getElementById("toggleIcon");
const body = document.body;
let sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';

// ✅ Footer Responsive Function
function updateFooterPosition() {
  const footer = document.getElementById('huFooter');
  
  if (isMobile) {
    // On mobile, footer always full width
    footer.style.left = '0';
    footer.style.width = '100%';
  } else {
    // On desktop, adjust based on sidebar state
    if (sidebarCollapsed) {
      footer.style.left = '60px';
      footer.style.width = 'calc(100% - 60px)';
    } else {
      footer.style.left = '240px';
      footer.style.width = 'calc(100% - 240px)';
    }
  }
}

// Initialize sidebar state
function initializeSidebar() {
    if (isMobile) {
        // On mobile, always start with sidebar hidden
        sidebar.classList.remove('collapsed', 'mobile-open');
        body.classList.remove('sidebar-collapsed');
        toggleIcon.classList.remove('fa-xmark');
        toggleIcon.classList.add('fa-bars');
    } else {
        // On desktop, restore saved state
        if (sidebarCollapsed) {
            sidebar.classList.add('collapsed');
            body.classList.add('sidebar-collapsed');
            toggleIcon.classList.remove('fa-bars');
            toggleIcon.classList.add('fa-xmark');
        } else {
            sidebar.classList.remove('collapsed');
            body.classList.remove('sidebar-collapsed');
            toggleIcon.classList.remove('fa-xmark');
            toggleIcon.classList.add('fa-bars');
        }
    }
    
    // Update footer position
    updateFooterPosition();
}

// Check screen size
function checkMobile() {
    isMobile = window.innerWidth <= 768;
    initializeSidebar();
}

// Toggle sidebar
toggleBtn.addEventListener('click', function() {
    if (isMobile) {
        // Mobile toggle
        sidebar.classList.toggle('mobile-open');
        toggleIcon.classList.toggle('fa-bars');
        toggleIcon.classList.toggle('fa-xmark');
    } else {
        // Desktop toggle with save
        sidebarCollapsed = !sidebarCollapsed;
        
        if (sidebarCollapsed) {
            sidebar.classList.add('collapsed');
            body.classList.add('sidebar-collapsed');
            toggleIcon.classList.remove('fa-bars');
            toggleIcon.classList.add('fa-xmark');
        } else {
            sidebar.classList.remove('collapsed');
            body.classList.remove('sidebar-collapsed');
            toggleIcon.classList.remove('fa-xmark');
            toggleIcon.classList.add('fa-bars');
        }
        
        localStorage.setItem('sidebarCollapsed', sidebarCollapsed);
        
        // Update footer position
        updateFooterPosition();
    }
});

// Close mobile sidebar when clicking outside
document.addEventListener('click', function(event) {
    if (isMobile && sidebar.classList.contains('mobile-open')) {
        if (!sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
            sidebar.classList.remove('mobile-open');
            toggleIcon.classList.remove('fa-xmark');
            toggleIcon.classList.add('fa-bars');
        }
    }
});

// Close sidebar on mobile when clicking a link
document.querySelectorAll('.sidebar a').forEach(link => {
    link.addEventListener('click', () => {
        if (isMobile) {
            sidebar.classList.remove('mobile-open');
            toggleIcon.classList.remove('fa-xmark');
            toggleIcon.classList.add('fa-bars');
        }
    });
});

// ✅ Submenu toggle
document.querySelectorAll(".menu-toggle").forEach(toggle => {
    toggle.addEventListener('click', function(e) {
        if (isMobile) e.stopPropagation();
        
        const submenu = document.getElementById(this.dataset.target);
        if (submenu.style.display === "block") {
            submenu.style.display = "none";
            this.classList.remove("open");
        } else {
            submenu.style.display = "block";
            this.classList.add("open");
        }
    });
});

// ✅ Profile modal
const profileModal = document.getElementById("profileModal");
document.getElementById("profileOpen").addEventListener('click', () => {
    profileModal.style.display = "flex";
    document.body.style.overflow = "hidden";
});

document.getElementById("closeProfile").addEventListener('click', () => {
    profileModal.style.display = "none";
    document.body.style.overflow = "auto";
});

// ✅ Overlay close buttons
document.querySelectorAll(".close-overlay").forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.getAttribute("data-close");
        document.getElementById(id).style.display = "none";
        document.body.style.overflow = "auto";
    });
});

// ✅ View profile button
document.getElementById("viewProfileBtn").addEventListener('click', () => {
    profileModal.style.display = "none";
    document.getElementById("overlayView").style.display = "flex";
    document.body.style.overflow = "hidden";
});

// ✅ Change password button
document.getElementById("changePasswordBtn").addEventListener('click', () => {
    profileModal.style.display = "none";
    document.getElementById("overlayPassword").style.display = "flex";
    document.body.style.overflow = "hidden";
});

// ✅ Update footer year
document.addEventListener('DOMContentLoaded', function() {
    const yearSpan = document.getElementById('currentYear');
    if (yearSpan) {
        yearSpan.textContent = new Date().getFullYear();
    }
    
    // Social links hover effects for touch devices
    const socialLinks = document.querySelectorAll('.social-link');
    socialLinks.forEach(link => {
        link.addEventListener('touchstart', function() {
            this.style.transform = 'translateY(-2px)';
        });
        
        link.addEventListener('touchend', function() {
            this.style.transform = 'translateY(0)';
        });
    });
});

// ✅ Close modals when clicking outside
window.addEventListener('click', function(event) {
    if (event.target === profileModal) {
        profileModal.style.display = "none";
        document.body.style.overflow = "auto";
    }
    
    const overlays = document.querySelectorAll('.overlay');
    overlays.forEach(overlay => {
        if (event.target === overlay) {
            overlay.style.display = "none";
            document.body.style.overflow = "auto";
        }
    });
});

// Initialize on load
checkMobile();
window.addEventListener('resize', function() {
    checkMobile();
});


</script>
</body>
</html>