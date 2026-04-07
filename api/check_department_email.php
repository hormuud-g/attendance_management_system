<?php
require_once(__DIR__ . '/../config/db_connect.php');

header('Content-Type: application/json');

if (isset($_GET['email'])) {
    $email = trim($_GET['email']);
    $exclude = $_GET['exclude'] ?? null;
    
    if ($exclude) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE email = ? AND department_id != ?");
        $stmt->execute([$email, $exclude]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE email = ?");
        $stmt->execute([$email]);
    }
    
    echo json_encode(['exists' => $stmt->fetchColumn() > 0]);
} else {
    echo json_encode(['exists' => false]);
}
?>