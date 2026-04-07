<?php


session_start();
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/includes/audit_helper.php';

// ✅ Record audit log before destroying session
if (isset($_SESSION['user'])) {
    $user_id = $_SESSION['user']['user_id'] ?? 0;
    add_audit_log($pdo, $user_id, 'logout', 'User logged out of the system');
}

// ✅ Destroy session safely
session_unset();
session_destroy();

// ✅ Clear cookies if used
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// ✅ Redirect to login page
header("Location: login.php");
exit;
?>
