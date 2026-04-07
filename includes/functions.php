<?php
// functions.php

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if function already exists (maybe declared in header.php)
if (!function_exists('hasPermission')) {
    function hasPermission($menu_item) {
        if (!isset($_SESSION['user']['user_id'])) {
            return false;
        }

        $user_id = $_SESSION['user']['user_id'];

        global $pdo;

        // If $pdo is not available, include the database connection
        if (!isset($pdo)) {
            require_once(__DIR__ . '/../config/db_connect.php');
        }

        // Check if user has permission for this menu item
        $stmt = $pdo->prepare("
            SELECT status 
            FROM user_permissions 
            WHERE user_id = ? AND menu_item = ?
        ");
        $stmt->execute([$user_id, $menu_item]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // If no specific permission exists, default to allowed (show menu item)
        if (!$result) {
            return true;
        }

        // Return true only if status is 'allowed'
        return $result['status'] === 'allowed';
    }
}