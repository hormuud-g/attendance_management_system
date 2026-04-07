<?php
session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Check if user is logged in and is Super Admin
if (!isset($_SESSION['user']) || strtolower($_SESSION['user']['role']) !== 'super_admin') {
    header("Location: ../login.php");
    exit;
}

$message = "";
$type = "";

/* ==================== AJAX REQUEST HANDLER ==================== */
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_user_data') {
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

    // Complete menu items for each role (as in sidebar)
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
        'campus_admin' => [
            'dashboard' => 'Dashboard',
            'university_menu' => 'University Menu',
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
        'faculty_admin' => [
            'dashboard' => 'Dashboard',
            'university_menu' => 'University Menu',
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
            'notifications' => 'Notifications'
        ],
        'department_admin' => [
            'dashboard' => 'Dashboard',
            'university_menu' => 'University Menu',
            'rooms' => 'Rooms',
            'room_allocation' => 'Room Allocation',
            'academic_menu' => 'Academic Menu',
            'academic_years' => 'Academic Years',
            'academic_terms' => 'Academic Terms',
            'semesters' => 'Semesters',
            'programs' => 'Programs',
            'classes' => 'Classes',
            'courses' => 'Courses',
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
            'notifications' => 'Notifications'
        ],
        'teacher' => [
            'dashboard' => 'Dashboard',
            'courses' => 'Courses',
            'timetable' => 'Timetable',
            'attendance' => 'Attendance',
            'reports' => 'Reports',
            'announcements' => 'Announcements'
        ],
        'student' => [
            'dashboard' => 'Dashboard',
            'courses' => 'Courses',
            'timetable' => 'Timetable',
            'attendance' => 'Attendance',
            'announcements' => 'Announcements'
        ],
        'parent' => [
            'dashboard' => 'Dashboard',
            'student_progress' => 'Student Progress',
            'attendance' => 'Attendance',
            'reports' => 'Reports',
            'announcements' => 'Announcements'
        ]
    ];

    $role = $user['role'];
    $menu_items = $menu_items_by_role[$role] ?? [];

    echo json_encode([
        'status' => 'success',
        'user' => $user,
        'permissions' => $user_permissions,
        'menu_items' => $menu_items
    ]);
    exit;
}

/* ==================== POST REQUEST HANDLER (Save Permissions) ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        if (isset($_POST['action']) && $_POST['action'] === 'update_permissions') {
            $user_id = intval($_POST['user_id']);
            
            // Delete existing permissions
            $stmt = $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?");
            $stmt->execute([$user_id]);
            
            // Insert new permissions (only for "allowed")
            if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
                $insertStmt = $pdo->prepare("
                    INSERT INTO user_permissions (user_id, menu_item, status)
                    VALUES (?, ?, 'allowed')
                ");
                
                foreach ($_POST['permissions'] as $menu_item => $status) {
                    if ($status === 'allowed') {
                        $insertStmt->execute([$user_id, $menu_item]);
                    }
                }
            }
            
            $message = "✅ User permissions updated successfully!";
            $type = "success";
        }
        
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "❌ Error: " . $e->getMessage();
        $type = "error";
    }
}

/* ==================== FETCH ALL USERS FOR DROPDOWN ==================== */
$users = $pdo->query("
    SELECT u.user_id, u.username, u.first_name, u.last_name, u.email, u.role, 
           u.status, u.created_at
    FROM users u
    ORDER BY u.role, u.first_name, u.last_name
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Permissions Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, #00843D 0%, #005a2b 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0, 132, 61, 0.2);
        }

        .header h1 {
            font-size: 28px;
            font-weight: 600;
        }

        .header h1 i {
            margin-right: 12px;
        }

        .subtitle {
            font-size: 16px;
            opacity: 0.9;
            margin-top: 5px;
        }

        .user-selection {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }

        .user-selection h3 {
            margin-bottom: 15px;
            color: #333;
        }

        .search-box {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
        }

        .search-box input {
            flex: 1;
            padding: 12px 15px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            font-size: 16px;
            transition: border 0.3s;
        }

        .search-box input:focus {
            outline: none;
            border-color: #00843D;
            box-shadow: 0 0 0 3px rgba(0, 132, 61, 0.1);
        }

        .user-dropdown {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e1e5eb;
            border-radius: 8px;
            font-size: 16px;
            background: white;
            cursor: pointer;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-weight: 500;
            animation: fadeIn 0.5s;
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            border-left: 5px solid #28a745;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border-left: 5px solid #dc3545;
        }

        .permissions-container {
            display: none;
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }

        .user-info-box {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border-left: 4px solid #00843D;
        }

        .user-info-box h3 {
            color: #00843D;
            margin-bottom: 10px;
        }

        .user-details {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 15px;
        }

        .user-detail-item {
            flex: 1;
            min-width: 200px;
        }

        .user-detail-item strong {
            display: block;
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
        }

        .role-badge {
            display: inline-block;
            padding: 5px 12px;
            background: #e9f7ef;
            color: #00843D;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
            margin-left: 10px;
        }

        .permission-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .permission-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #eaeaea;
            position: relative;
        }

        .permission-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        }

        .permission-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .permission-header strong {
            font-size: 16px;
            color: #333;
        }

        .permission-toggle {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }

        .permission-toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .permission-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }

        .permission-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        .permission-toggle input:checked + .permission-slider {
            background-color: #00843D;
        }

        .permission-toggle input:checked + .permission-slider:before {
            transform: translateX(24px);
        }

        .permission-key {
            display: block;
            color: #666;
            font-size: 13px;
            margin-top: 8px;
            background: #f8f9fa;
            padding: 5px 8px;
            border-radius: 4px;
            font-family: monospace;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid #eee;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: #00843D;
            color: white;
        }

        .btn-primary:hover {
            background: #005a2b;
            box-shadow: 0 5px 15px rgba(0, 132, 61, 0.3);
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #666;
            font-size: 18px;
        }

        .empty-state i {
            font-size: 50px;
            margin-bottom: 15px;
            color: #ddd;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 768px) {
            .permission-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }
            
            .user-details {
                flex-direction: column;
            }
            
            .search-box {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fa fa-user-shield"></i> User Permissions Management</h1>
            <p class="subtitle">Customize menu access for each user. Toggle OFF to hide menu items, ON to show them.</p>
        </div>

        <?php if($message): ?>
        <div class="alert <?= htmlspecialchars($type) ?>">
            <strong><?= htmlspecialchars($message) ?></strong>
        </div>
        <script>
            setTimeout(() => {
                const el = document.querySelector('.alert');
                if(el) el.remove();
            }, 5000);
        </script>
        <?php endif; ?>

        <!-- User Selection Section -->
        <div class="user-selection">
            <h3><i class="fa fa-search"></i> Find User to Manage Permissions</h3>
            
            <div class="search-box">
                <input type="text" id="userSearch" placeholder="Search by username, name, email, or role..." onkeyup="filterUsers()">
                <select id="userSelect" class="user-dropdown" onchange="loadUserPermissions(this.value)">
                    <option value="">-- Select a User --</option>
                    <?php foreach($users as $u): ?>
                        <option value="<?= $u['user_id'] ?>" 
                                data-username="<?= htmlspecialchars($u['username']) ?>"
                                data-name="<?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>"
                                data-email="<?= htmlspecialchars($u['email']) ?>"
                                data-role="<?= htmlspecialchars($u['role']) ?>">
                            <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name'] . ' (@' . $u['username'] . ') - ' . ucfirst($u['role'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p style="color: #666; font-size: 14px; margin-top: 10px;">
                <i class="fa fa-info-circle"></i> Select a user above to view and manage their sidebar menu permissions
            </p>
        </div>

        <!-- Permissions Form (Hidden by default) -->
        <form method="POST" id="permissionsForm" class="permissions-container">
            <input type="hidden" name="action" value="update_permissions">
            <input type="hidden" name="user_id" id="formUserId">
            
            <div id="userInfo"></div>
            
            <div id="permissionsGrid">
                <!-- Permissions will be loaded here -->
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="resetPermissionsForm()">
                    <i class="fa fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save"></i> Save Permissions
                </button>
            </div>
        </form>

        <!-- Instructions Section -->
        <div style="background: white; padding: 20px; border-radius: 10px; margin-top: 30px; border-left: 4px solid #00843D;">
            <h3><i class="fa fa-question-circle"></i> How Permissions Work</h3>
            <ul style="margin-top: 15px; color: #555; line-height: 1.8;">
                <li><strong>Toggle ON (Green):</strong> User can see this menu item in their sidebar</li>
                <li><strong>Toggle OFF (Gray):</strong> User CANNOT see this menu item in their sidebar</li>
                <li>Permissions are saved immediately when you click "Save Permissions"</li>
                <li>Users need to refresh their browser to see permission changes</li>
                <li>Each role has different available menu items</li>
            </ul>
        </div>
    </div>

    <script>
        // Filter users in dropdown based on search input
        function filterUsers() {
            const input = document.getElementById('userSearch');
            const filter = input.value.toLowerCase();
            const select = document.getElementById('userSelect');
            const options = select.getElementsByTagName('option');
            
            // Show all options first
            for (let i = 0; i < options.length; i++) {
                options[i].style.display = "";
            }
            
            // If search is empty, show all
            if (filter.trim() === '') return;
            
            // Hide options that don't match
            for (let i = 1; i < options.length; i++) {
                const text = options[i].textContent.toLowerCase();
                const username = options[i].getAttribute('data-username').toLowerCase();
                const name = options[i].getAttribute('data-name').toLowerCase();
                const email = options[i].getAttribute('data-email').toLowerCase();
                const role = options[i].getAttribute('data-role').toLowerCase();
                
                if (text.includes(filter) || username.includes(filter) || 
                    name.includes(filter) || email.includes(filter) || role.includes(filter)) {
                    options[i].style.display = "";
                } else {
                    options[i].style.display = "none";
                }
            }
        }

        // Load user permissions when user is selected
        function loadUserPermissions(userId) {
            if (!userId) {
                document.getElementById('permissionsForm').style.display = 'none';
                return;
            }
            
            // Show loading state
            const form = document.getElementById('permissionsForm');
            form.style.display = 'block';
            document.getElementById('permissionsGrid').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class="fa fa-spinner fa-spin" style="font-size: 30px; color: #00843D;"></i>
                    <p style="margin-top: 15px; color: #666;">Loading user permissions...</p>
                </div>
            `;
            
            // Fetch user data and permissions via AJAX
            fetch(`?ajax=get_user_data&user_id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        displayUserPermissions(data);
                    } else {
                        alert('Error: ' + data.message);
                        form.style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading user permissions');
                    form.style.display = 'none';
                });
        }

        // Display user permissions in the form
        function displayUserPermissions(data) {
            const user = data.user;
            const permissions = data.permissions || {};
            const menuItems = data.menu_items;
            
            // Set form user ID
            document.getElementById('formUserId').value = user.user_id;
            
            // Display user info
            document.getElementById('userInfo').innerHTML = `
                <div class="user-info-box">
                    <h3>Managing Permissions for: ${user.first_name} ${user.last_name} <span class="role-badge">${user.role}</span></h3>
                    <div class="user-details">
                        <div class="user-detail-item">
                            <strong>Username</strong>
                            <span>${user.username}</span>
                        </div>
                        <div class="user-detail-item">
                            <strong>Email</strong>
                            <span>${user.email}</span>
                        </div>
                        <div class="user-detail-item">
                            <strong>Status</strong>
                            <span style="color: ${user.status === 'active' ? '#28a745' : '#dc3545'}; font-weight: 600;">
                                ${user.status.charAt(0).toUpperCase() + user.status.slice(1)}
                            </span>
                        </div>
                        <div class="user-detail-item">
                            <strong>Total Menu Items</strong>
                            <span>${Object.keys(menuItems).length} available</span>
                        </div>
                    </div>
                </div>
            `;
            
            // Build permissions grid
            if (Object.keys(menuItems).length > 0) {
                let html = `
                    <h3 style="margin-bottom: 20px; color: #333;">Sidebar Menu Permissions</h3>
                    <p style="margin-bottom: 25px; color: #666;">
                        <i class="fa fa-info-circle"></i> Toggle each item ON/OFF to control what appears in the user's sidebar menu
                    </p>
                    <div class="permission-grid">
                `;
                
                // Sort menu items alphabetically
                const sortedItems = Object.entries(menuItems).sort((a, b) => a[1].localeCompare(b[1]));
                
                sortedItems.forEach(([menuKey, menuLabel]) => {
                    const isAllowed = permissions[menuKey] === 'allowed';
                    html += `
                        <div class="permission-card">
                            <div class="permission-header">
                                <strong>${menuLabel}</strong>
                                <label class="permission-toggle">
                                    <input type="checkbox" name="permissions[${menuKey}]" value="allowed" ${isAllowed ? 'checked' : ''}>
                                    <span class="permission-slider"></span>
                                </label>
                            </div>
                            <span class="permission-key">${menuKey}</span>
                        </div>
                    `;
                });
                
                html += `</div>`;
                document.getElementById('permissionsGrid').innerHTML = html;
                
                // Add change event to all toggles
                document.querySelectorAll('.permission-toggle input').forEach(toggle => {
                    toggle.addEventListener('change', function() {
                        this.value = this.checked ? 'allowed' : 'restricted';
                    });
                });
            } else {
                document.getElementById('permissionsGrid').innerHTML = `
                    <div class="empty-state">
                        <i class="fa fa-exclamation-triangle"></i>
                        <p>No menu items available for role: ${user.role}</p>
                        <p style="font-size: 14px; margin-top: 10px;">This role may not have any sidebar menu items configured.</p>
                    </div>
                `;
            }
            
            // Scroll to form
            document.getElementById('permissionsForm').scrollIntoView({ 
                behavior: 'smooth', 
                block: 'start' 
            });
        }

        // Reset the permissions form
        function resetPermissionsForm() {
            document.getElementById('permissionsForm').style.display = 'none';
            document.getElementById('userSelect').value = '';
            document.getElementById('userSearch').value = '';
            document.getElementById('permissionsGrid').innerHTML = '';
            document.getElementById('userInfo').innerHTML = '';
            filterUsers(); // Reset filter
        }

        // Form submission handler
        document.getElementById('permissionsForm').addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to update these permissions?\n\nThe user will need to refresh their browser to see changes.')) {
                e.preventDefault();
                return false;
            }
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
            
            // Re-enable button after 3 seconds in case of error
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 3000);
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Focus on search input
            document.getElementById('userSearch').focus();
            
            // Auto-submit filter when Enter is pressed in search
            document.getElementById('userSearch').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    // If only one user matches, select it
                    const select = document.getElementById('userSelect');
                    const visibleOptions = Array.from(select.options)
                        .filter(opt => opt.style.display !== 'none' && opt.value !== '');
                    
                    if (visibleOptions.length === 1) {
                        visibleOptions[0].selected = true;
                        loadUserPermissions(visibleOptions[0].value);
                    }
                }
            });
            
            // Auto-refresh page after successful form submission
            <?php if($type === 'success'): ?>
                setTimeout(() => {
                    window.location.href = window.location.pathname;
                }, 2000);
            <?php endif; ?>
        });
    </script>
</body>
</html>