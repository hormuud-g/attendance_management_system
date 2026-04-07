<?php
require_once(__DIR__ . '/../config/db_connect.php');

header('Content-Type: application/json');

if (isset($_GET['phone'])) {
    $phone = trim($_GET['phone']);
    $exclude = $_GET['exclude'] ?? null;
    
    if ($exclude) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE phone_number = ? AND department_id != ?");
        $stmt->execute([$phone, $exclude]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE phone_number = ?");
        $stmt->execute([$phone]);
    }
    
    echo json_encode(['exists' => $stmt->fetchColumn() > 0]);
} else {
    echo json_encode(['exists' => false]);
}
?>