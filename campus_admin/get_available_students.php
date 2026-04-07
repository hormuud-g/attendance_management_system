<?php
session_start();
require_once(__DIR__ . '/../config/db_connect.php');

$parent_id = intval($_GET['parent_id'] ?? 0);
if (!$parent_id) {
    echo json_encode([]);
    exit;
}

// Get students (simplified version without campus check for now)
$sql = "SELECT s.* FROM students s 
        WHERE s.status = 'active' 
        AND s.student_id NOT IN (
            SELECT student_id FROM parent_student WHERE parent_id = ?
        )
        ORDER BY s.full_name";
        
$stmt = $pdo->prepare($sql);
$stmt->execute([$parent_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($students);
?>