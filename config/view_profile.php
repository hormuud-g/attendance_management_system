<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');
if (empty($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

$user = $_SESSION['user'];
$user_id = $user['user_id'];

/* === FETCH USER DATA === */
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$profile) {
    echo "User not found.";
    exit;
}

$photo = !empty($profile['profile_photo_path'])
  ? '../' . $profile['profile_photo_path']
  : '../uploads/profiles/default.png';

/* === FETCH LINKED TABLE DATA DYNAMICALLY === */
$linked_info = null;
$linked_table_name = '';

if (!empty($profile['linked_table']) && !empty($profile['linked_id'])) {
    $table = $profile['linked_table'];
    $id = (int)$profile['linked_id'];

    // ✅ ALLOWED TABLES WITH THEIR PRIMARY KEYS
    $allowed_tables = [
        'academic_term' => ['academic_term_id', 'term_name'],
        'academic_year' => ['academic_year_id', 'year_name'],
        'announcement' => ['announcement_id', 'title'],
        'attendance' => ['attendance_id', 'attendance_date'],
        'attendance_audit' => ['audit_id', 'action_type'],
        'attendance_lock' => ['lock_id', 'lock_date'],
        'audit_log' => ['log_id', 'action'],
        'campus' => ['campus_id', 'campus_name'],
        'class_section' => ['section_id', 'section_name'],
        'departments' => ['department_id', 'department_name'],
        'faculties' => ['faculty_id', 'faculty_name'],
        'faculty_campus' => ['faculty_campus_id', 'faculty_id'],
        'notifications' => ['notification_id', 'title'],
        'parent_student' => ['parent_student_id', 'parent_id'],
        'parents' => ['parent_id', 'parent_name'],
        'programs' => ['program_id', 'program_name'],
        'promotion_history' => ['promotion_id', 'promotion_date'],
        'room_allocation' => ['allocation_id', 'allocation_date'],
        'rooms' => ['room_id', 'room_name'],
        'semester' => ['semester_id', 'semester_name'],
        'student_enroll' => ['enrollment_id', 'enrollment_date'],
        'student_subject' => ['student_subject_id', 'student_id'],
        'students' => ['student_id', 'full_name'],
        'subject' => ['subject_id', 'subject_name'],
        'teacher_attendance' => ['id', 'date'],
        'teachers' => ['teacher_id', 'teacher_name'],
        'timetable' => ['timetable_id', 'day_of_week'],
        'users' => ['user_id', 'username']
    ];

    if (array_key_exists($table, $allowed_tables)) {
        $id_column = $allowed_tables[$table][0];
        $linked_table_name = $table;
        
        try {
            $query = "SELECT * FROM `$table` WHERE `$id_column` = ?";
            $stmt2 = $pdo->prepare($query);
            $stmt2->execute([$id]);
            $linked_info = $stmt2->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Profile linked table error: " . $e->getMessage());
            $linked_info = null;
        }
    }
}

/* === FORMAT DATE FUNCTION === */
function formatDate($date) {
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') {
        return '-';
    }
    return date('M j, Y g:i A', strtotime($date));
}

/* === FORMAT STATUS FUNCTION === */
function formatStatus($status) {
    $status = strtolower($status ?? 'inactive');
    switch ($status) {
        case 'active':
            return "<span class='status-badge status-active'><i class='fas fa-check-circle'></i> Active</span>";
        case 'pending':
            return "<span class='status-badge status-warning'><i class='fas fa-clock'></i> Pending</span>";
        case 'inactive':
            return "<span class='status-badge status-inactive'><i class='fas fa-times-circle'></i> Inactive</span>";
        case 'available':
            return "<span class='status-badge status-active'><i class='fas fa-check'></i> Available</span>";
        case 'maintenance':
            return "<span class='status-badge status-warning'><i class='fas fa-tools'></i> Maintenance</span>";
        case 'completed':
            return "<span class='status-badge status-success'><i class='fas fa-check-double'></i> Completed</span>";
        case 'locked':
            return "<span class='status-badge status-inactive'><i class='fas fa-lock'></i> Locked</span>";
        case 'unlocked':
            return "<span class='status-badge status-active'><i class='fas fa-unlock'></i> Unlocked</span>";
        default:
            return "<span class='status-badge status-inactive'><i class='fas fa-question-circle'></i> " . ucfirst($status) . "</span>";
    }
}

/* === FILTER SENSITIVE FIELDS === */
function filterField($key, $value) {
    $sensitive_fields = ['password', 'token', 'secret', 'api_key', 'auth_key', 'remember_token'];
    
    foreach ($sensitive_fields as $sensitive) {
        if (stripos($key, $sensitive) !== false) {
            return '••••••••';
        }
    }
    
    return $value;
}

/* === FORMAT FIELD NAMES === */
function formatFieldName($key) {
    $replacements = [
        '_id' => ' ID',
        '_name' => ' Name',
        '_date' => ' Date',
        '_time' => ' Time',
        '_at' => ' At',
        'faculty' => 'Faculty',
        'department' => 'Department',
        'campus' => 'Campus',
        'teacher' => 'Teacher',
        'student' => 'Student',
        'subject' => 'Subject',
        'program' => 'Program',
        'section' => 'Section',
        'room' => 'Room',
        'academic' => 'Academic',
        'attendance' => 'Attendance',
        'promotion' => 'Promotion',
        'timetable' => 'Timetable',
        'enrollment' => 'Enrollment',
        'allocation' => 'Allocation',
        'notification' => 'Notification',
        'announcement' => 'Announcement',
        'audit' => 'Audit',
        'parent' => 'Parent'
    ];
    
    $formatted = str_replace(array_keys($replacements), array_values($replacements), $key);
    return ucwords(str_replace('_', ' ', $formatted));
}

/* === FORMAT FIELD VALUE === */
function formatFieldValue($key, $value) {
    if (empty($value) || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '<span class="text-muted">-</span>';
    }
    
    // Date fields
    $date_fields = ['date', 'created', 'updated', 'start', 'end', 'attendance_date', 'promotion_date', 'enrollment_date', 'lock_date', 'allocation_date'];
    foreach ($date_fields as $date_field) {
        if (stripos($key, $date_field) !== false) {
            return '<span class="text-date">' . formatDate($value) . '</span>';
        }
    }
    
    // Status fields
    $status_fields = ['status'];
    foreach ($status_fields as $status_field) {
        if (stripos($key, $status_field) !== false) {
            return formatStatus($value);
        }
    }
    
    // Boolean fields
    if ($value === '1' || $value === 1) return '<span class="text-success">Yes</span>';
    if ($value === '0' || $value === 0) return '<span class="text-danger">No</span>';
    
    // Long text truncation
    if (strlen($value) > 100) {
        return '<span title="' . htmlspecialchars($value) . '">' . htmlspecialchars(substr($value, 0, 100)) . '...</span>';
    }
    
    return htmlspecialchars($value);
}

/* === GET TABLE DISPLAY NAME === */
function getTableDisplayName($table_name) {
    $display_names = [
        'academic_term' => 'Academic Term',
        'academic_year' => 'Academic Year',
        'announcement' => 'Announcement',
        'attendance' => 'Student Attendance',
        'attendance_audit' => 'Attendance Audit',
        'attendance_lock' => 'Attendance Lock',
        'audit_log' => 'Audit Log',
        'campus' => 'Campus',
        'class_section' => 'Class Section',
        'departments' => 'Department',
        'faculties' => 'Faculty',
        'faculty_campus' => 'Faculty Campus',
        'notifications' => 'Notification',
        'parent_student' => 'Parent Student Link',
        'parents' => 'Parent',
        'programs' => 'Program',
        'promotion_history' => 'Promotion History',
        'room_allocation' => 'Room Allocation',
        'rooms' => 'Room',
        'semester' => 'Semester',
        'student_enroll' => 'Student Enrollment',
        'student_subject' => 'Student Subject',
        'students' => 'Student',
        'subject' => 'Subject',
        'teacher_attendance' => 'Teacher Attendance',
        'teachers' => 'Teacher',
        'timetable' => 'Timetable',
        'users' => 'User'
    ];
    
    return $display_names[$table_name] ?? ucwords(str_replace('_', ' ', $table_name));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Hormuud University</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* 🎨 Hormuud University Official Colors */
        :root {
            --hu-green: #00843D;
            --hu-blue: #0072CE;
            --hu-light-green: #00A651;
            --hu-dark-blue: #0056b3;
            --hu-dark-gray: #2c3e50;
            --hu-medium-gray: #34495e;
            --hu-light-gray: #ecf0f1;
            --hu-white: #ffffff;
            --hu-red: #e74c3c;
            --hu-orange: #f39c12;
            --hu-purple: #9b59b6;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--hu-light-gray) 0%, #dfe6e9 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .profile-container {
            background: var(--hu-white);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            width: 100%;
            max-width: 1000px;
            display: grid;
            grid-template-columns: 350px 1fr;
            min-height: 600px;
        }

        /* Sidebar Styles */
        .profile-sidebar {
            background: linear-gradient(135deg, var(--hu-blue) 0%, var(--hu-green) 100%);
            color: var(--hu-white);
            padding: 40px 30px;
            text-align: center;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .profile-sidebar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,0 L100,0 L100,100 Z" fill="rgba(255,255,255,0.1)"/></svg>');
            background-size: cover;
        }

        .profile-image {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 5px solid rgba(255, 255, 255, 0.3);
            object-fit: cover;
            margin-bottom: 20px;
            position: relative;
            z-index: 2;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.2);
        }

        .user-name {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
            position: relative;
            z-index: 2;
        }

        .user-role {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 20px;
            position: relative;
            z-index: 2;
        }

        .user-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 30px;
            position: relative;
            z-index: 2;
            width: 100%;
        }

        .stat-item {
            background: rgba(255, 255, 255, 0.2);
            padding: 15px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }

        .stat-value {
            font-size: 20px;
            font-weight: 700;
            display: block;
        }

        .stat-label {
            font-size: 12px;
            opacity: 0.8;
            display: block;
            margin-top: 5px;
        }

        /* Main Content Styles */
        .profile-content {
            padding: 40px;
            overflow-y: auto;
            max-height: 600px;
        }

        .content-section {
            margin-bottom: 30px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--hu-blue);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--hu-light-gray);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--hu-green);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--hu-medium-gray);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 14px;
            color: var(--hu-dark-gray);
            font-weight: 500;
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-active {
            background: rgba(0, 132, 61, 0.1);
            color: var(--hu-green);
        }

        .status-inactive {
            background: rgba(231, 76, 60, 0.1);
            color: var(--hu-red);
        }

        .status-warning {
            background: rgba(243, 156, 18, 0.1);
            color: var(--hu-orange);
        }

        .status-success {
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
        }

        /* Text Styles */
        .text-muted {
            color: #95a5a6 !important;
            font-style: italic;
        }

        .text-date {
            color: var(--hu-purple);
            font-weight: 500;
        }

        .text-success {
            color: var(--hu-green);
            font-weight: 600;
        }

        .text-danger {
            color: var(--hu-red);
            font-weight: 600;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            font-size: 14px;
        }

        .btn-primary {
            background: var(--hu-blue);
            color: var(--hu-white);
        }

        .btn-primary:hover {
            background: var(--hu-dark-blue);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--hu-light-gray);
            color: var(--hu-dark-gray);
        }

        .btn-secondary:hover {
            background: #bdc3c7;
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--hu-green);
            color: var(--hu-white);
        }

        .btn-success:hover {
            background: var(--hu-light-green);
            transform: translateY(-2px);
        }

        /* Scrollbar */
        .profile-content::-webkit-scrollbar {
            width: 6px;
        }

        .profile-content::-webkit-scrollbar-track {
            background: var(--hu-light-gray);
        }

        .profile-content::-webkit-scrollbar-thumb {
            background: var(--hu-blue);
            border-radius: 3px;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .profile-container {
                grid-template-columns: 1fr;
            }
            
            .profile-sidebar {
                padding: 30px 20px;
            }
            
            .profile-content {
                padding: 30px 20px;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Linked Info Section */
        .linked-info-section {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            padding: 25px;
            margin-top: 20px;
            border-left: 4px solid var(--hu-green);
        }

        .linked-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            color: var(--hu-dark-gray);
        }

        .linked-header i {
            color: var(--hu-green);
            font-size: 20px;
        }

        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: #7f8c8d;
        }

        .no-data i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="profile-container">
        <!-- Sidebar -->
        <div class="profile-sidebar">
            <img src="<?= htmlspecialchars($photo) ?>" alt="Profile Photo" class="profile-image" onerror="this.src='../uploads/profiles/default.png'">
            <h1 class="user-name"><?= htmlspecialchars($profile['username'] ?? 'User') ?></h1>
            <p class="user-role"><?= htmlspecialchars($profile['role'] ?? 'No Role') ?></p>
            
            <div class="user-stats">
                <div class="stat-item">
                    <span class="stat-value"><?= date('Y') ?></span>
                    <span class="stat-label">Academic Year</span>
                </div>
                <div class="stat-item">
                    <span class="stat-value"><?= formatStatus($profile['status'] ?? 'inactive') ?></span>
                    <span class="stat-label">Account Status</span>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="profile-content">
            <!-- User Information Section -->
            <div class="content-section">
                <h2 class="section-title">
                    <i class="fas fa-user-circle"></i>
                    Personal Information
                </h2>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Username</span>
                        <span class="info-value"><?= htmlspecialchars($profile['username'] ?? '-') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Email Address</span>
                        <span class="info-value"><?= htmlspecialchars($profile['email'] ?? '-') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Phone Number</span>
                        <span class="info-value"><?= htmlspecialchars($profile['phone_number'] ?? '-') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">User Role</span>
                        <span class="info-value"><?= htmlspecialchars($profile['role'] ?? '-') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Account Status</span>
                        <span class="info-value"><?= formatStatus($profile['status'] ?? 'inactive') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Member Since</span>
                        <span class="info-value"><?= formatDate($profile['created_at'] ?? '') ?></span>
                    </div>
                </div>
            </div>

            <!-- System Information Section -->
            <div class="content-section">
                <h2 class="section-title">
                    <i class="fas fa-cog"></i>
                    System Information
                </h2>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Linked Table</span>
                        <span class="info-value"><?= htmlspecialchars($profile['linked_table'] ?? 'None') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Linked ID</span>
                        <span class="info-value"><?= htmlspecialchars($profile['linked_id'] ?? 'None') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Profile Created</span>
                        <span class="info-value"><?= formatDate($profile['created_at'] ?? '') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Last Updated</span>
                        <span class="info-value"><?= formatDate($profile['updated_at'] ?? '') ?></span>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <!-- <div class="action-buttons">
                <a href="edit_profile.php" class="btn btn-primary">
                    <i class="fas fa-edit"></i> Edit Profile
                </a>
                <a href="../dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="../logout.php" class="btn btn-success">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div> -->
        </div>
    </div>

    <script>
        // Auto-close after 5 minutes of inactivity
        let timeout;
        function resetTimer() {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                if(confirm('Session timeout. Close profile?')) {
                    window.close();
                }
            }, 300000); // 5 minutes
        }

        document.addEventListener('mousemove', resetTimer);
        document.addEventListener('keypress', resetTimer);
        document.addEventListener('click', resetTimer);
        resetTimer();

        // Handle image loading errors
        document.querySelector('.profile-image').addEventListener('error', function() {
            this.src = '../uploads/profiles/default.png';
        });

        // Add some interactive effects
        document.querySelectorAll('.btn').forEach(btn => {
            btn.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
            });
            btn.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>