<?php
require_once(__DIR__ . '/../config/db_connect.php');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'check_head':
            $head = trim($_POST['head']);
            $id = $_POST['id'] ?? null;
            
            if ($id) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE head_of_department = ? AND head_of_department != '' AND department_id != ?");
                $stmt->execute([$head, $id]);
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE head_of_department = ? AND head_of_department != ''");
                $stmt->execute([$head]);
            }
            
            echo json_encode(['exists' => $stmt->fetchColumn() > 0]);
            break;
            
        case 'check_email':
            $email = trim($_POST['email']);
            $id = $_POST['id'] ?? null;
            
            if ($id) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE email = ? AND email != '' AND department_id != ?");
                $stmt->execute([$email, $id]);
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE email = ? AND email != ''");
                $stmt->execute([$email]);
            }
            
            echo json_encode(['exists' => $stmt->fetchColumn() > 0]);
            break;
            
        default:
            echo json_encode(['exists' => false]);
    }
}
?>