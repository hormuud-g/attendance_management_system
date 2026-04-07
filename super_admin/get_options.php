<?php
// get_options.php
require_once(__DIR__ . '/../config/db_connect.php');
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
try {
  if ($action === 'faculties') {
    // expects campus_id
    $campus_id = intval($_GET['campus_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT faculty_id, faculty_name, campus_id FROM faculties WHERE campus_id = ? ORDER BY faculty_name");
    $stmt->execute([$campus_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'ok','data'=>$rows]);
    exit;
  }

  if ($action === 'departments') {
    // expects faculty_id
    $faculty_id = intval($_GET['faculty_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT department_id, department_name, faculty_id FROM departments WHERE faculty_id = ? ORDER BY department_name");
    $stmt->execute([$faculty_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'ok','data'=>$rows]);
    exit;
  }

  if ($action === 'rooms_by_faculty') {
    // optional: return rooms related to the faculty (adjust query if rooms link differently)
    $faculty_id = intval($_GET['faculty_id'] ?? 0);
    // If your rooms table has faculty_id or department_id, change WHERE clause accordingly.
    // Example assumes rooms table has faculty_id:
    $stmt = $pdo->prepare("SELECT room_id, room_name, room_code FROM rooms WHERE (faculty_id = ? OR faculty_id IS NULL) AND status!='inactive' ORDER BY room_name");
    $stmt->execute([$faculty_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'ok','data'=>$rows]);
    exit;
  }

  // fallback: return empty
  echo json_encode(['status'=>'error','message'=>'Invalid action']);
} catch (Exception $e) {
  echo json_encode(['status'=>'error','message'=>'Server error']);
}
