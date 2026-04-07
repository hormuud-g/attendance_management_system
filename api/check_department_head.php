<?php
require_once(__DIR__ . '/../config/db_connect.php');

header('Content-Type: application/json');

if (isset($_GET['head'])) {
    $head = trim($_GET['head']);
    $exclude = $_GET['exclude'] ?? null;
    
    if ($exclude) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE head_of_department = ? AND department_id != ?");
        $stmt->execute([$head, $exclude]);
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE head_of_department = ?");
        $stmt->execute([$head]);
    }
    
    echo json_encode(['exists' => $stmt->fetchColumn() > 0]);
} else {
    echo json_encode(['exists' => false]);
}
?>