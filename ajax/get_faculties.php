<?php
require_once(__DIR__ . '/../../config/db_connect.php');
header('Content-Type: application/json; charset=utf-8');

$campus_id = isset($_GET['campus_id']) ? intval($_GET['campus_id']) : 0;

if ($campus_id <= 0) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT faculty_id, faculty_name 
        FROM faculties 
        WHERE campus_id = ? AND status = 'active'
        ORDER BY faculty_name ASC
    ");
    $stmt->execute([$campus_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
