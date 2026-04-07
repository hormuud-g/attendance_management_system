<?php
require_once(__DIR__ . '/../../config/db_connect.php');
header('Content-Type: application/json; charset=utf-8');

try {
    $stmt = $pdo->query("
        SELECT campus_id, campus_name 
        FROM campus 
        WHERE status = 'active'
        ORDER BY campus_name ASC
    ");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
