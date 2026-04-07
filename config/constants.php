<?php
// constants.php

define('DB_HOST', 'localhost');
define('DB_NAME', 'attendance_system');
define('DB_USER', 'root');
define('DB_PASS', '');

date_default_timezone_set('Africa/Mogadishu');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>
