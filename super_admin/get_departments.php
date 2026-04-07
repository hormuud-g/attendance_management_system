<?php
require_once(__DIR__ . '/../config/db_connect.php');

header('Content-Type: application/json');

$faculty_id = $_GET['faculty_id'] ?? 0;

if (!$faculty_id) {
    echo json_encode(['status' => 'error', 'message' => 'Faculty ID required']);
    exit;
}

// Get departments for this faculty
$stmt = $pdo->prepare("
    SELECT department_id, department_name 
    FROM departments 
    WHERE faculty_id = ? 
    ORDER BY department_name
");
$stmt->execute([$faculty_id]);
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($departments) {
    echo json_encode(['status' => 'success', 'departments' => $departments]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'No departments found for this faculty']);
}
?>