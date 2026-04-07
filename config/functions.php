<?php
require_once __DIR__ . '/db_connect.php';

// ✅ CRUD helper functions
function getAll($table) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM $table");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getById($table, $id_field, $id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE $id_field = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function insertRecord($table, $data) {
    global $pdo;
    $fields = implode(",", array_keys($data));
    $placeholders = ":" . implode(",:", array_keys($data));
    $stmt = $pdo->prepare("INSERT INTO $table ($fields) VALUES ($placeholders)");
    return $stmt->execute($data);
}

function updateRecord($table, $data, $id_field, $id) {
    global $pdo;
    $setPart = implode(", ", array_map(fn($k) => "$k = :$k", array_keys($data)));
    $stmt = $pdo->prepare("UPDATE $table SET $setPart WHERE $id_field = :id");
    $data['id'] = $id;
    return $stmt->execute($data);
}

function deleteRecord($table, $id_field, $id) {
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM $table WHERE $id_field = ?");
    return $stmt->execute([$id]);
}

function showAlert($message, $type = 'success') {
    echo "<div class='alert $type'>$message</div>";
}
?>
