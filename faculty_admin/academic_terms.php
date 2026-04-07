<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(__DIR__ . '/../config/db_connect.php');

// ✅ Access Control - Only Faculty Admin
if (strtolower($_SESSION['user']['role'] ?? '') !== 'faculty_admin') {
    header("Location: ../unauthorized.php");
    exit;
}

// ✅ Get current faculty ID from user session
$current_faculty_id = $_SESSION['user']['linked_id'] ?? null;

// ✅ Get faculty details for display
$faculty_name = '';
$faculty_code = '';

if ($current_faculty_id) {
    $stmt = $pdo->prepare("SELECT faculty_name, faculty_code FROM faculties WHERE faculty_id = ?");
    $stmt->execute([$current_faculty_id]);
    $faculty_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $faculty_name = $faculty_data['faculty_name'] ?? 'Unknown Faculty';
    $faculty_code = $faculty_data['faculty_code'] ?? '';
}

$message = "";
$type = "";

/* ===========================================
   FETCH ALL ACADEMIC TERMS (read-only for faculty)
=========================================== */
try {
    $stmt = $pdo->query("
        SELECT t.*, y.year_name 
        FROM academic_term t
        JOIN academic_year y ON y.academic_year_id = t.academic_year_id
        ORDER BY t.academic_term_id DESC
    ");
    $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "❌ " . $e->getMessage();
    $type = "error";
    $terms = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Academic Terms | Faculty Admin - <?= htmlspecialchars($faculty_name) ?> | Hormuud University</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="../images.png">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
:root {
  --green: #00843D;
  --blue: #0072CE;
  --red: #C62828;
  --bg: #F5F9F7;
  --dark: #2C3E50;
  --light-gray: #f8fafc;
  --white: #ffffff;
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: 'Poppins', sans-serif;
}

body {
  background: var(--bg);
  color: var(--dark);
  min-height: 100vh;
}

.main-content {
  padding: 20px;
  margin-top: 90px;
  margin-left: 250px;
  transition: all .3s;
}

.sidebar.collapsed ~ .main-content {
  margin-left: 70px;
}

/* Page Header */
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 25px;
  padding: 20px 25px;
  background: var(--white);
  border-radius: 12px;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
  border-left: 6px solid var(--green);
}

.page-header h1 {
  color: var(--blue);
  font-size: 24px;
  font-weight: 700;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 12px;
}

.page-header h1 i {
  color: var(--white);
  background: var(--green);
  padding: 12px;
  border-radius: 10px;
  box-shadow: 0 4px 10px rgba(0, 168, 89, 0.2);
}

.faculty-badge {
  font-size: 14px;
  font-weight: 400;
  background: rgba(0, 114, 206, 0.1);
  color: var(--blue);
  padding: 5px 15px;
  border-radius: 20px;
  margin-left: 15px;
}

.faculty-badge i {
  margin-right: 5px;
  color: var(--blue);
}

.info-message {
  background: #e8f5e9;
  color: var(--green);
  padding: 12px 20px;
  border-radius: 8px;
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 10px;
  border-left: 4px solid var(--green);
  font-size: 14px;
}

.info-message i {
  font-size: 18px;
}

/* Table Styles */
.table-wrapper {
  overflow-x: auto;
  background: var(--white);
  border-radius: 12px;
  box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
  border: 1px solid #eee;
}

.table-header {
  padding: 20px 25px;
  border-bottom: 1px solid #eee;
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: linear-gradient(to right, #f9f9f9, var(--white));
}

.table-header h3 {
  color: var(--dark);
  font-size: 16px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 8px;
}

.table-header h3 i {
  color: var(--green);
}

.results-count {
  color: #666;
  font-size: 14px;
  background: #f0f0f0;
  padding: 5px 12px;
  border-radius: 20px;
}

table {
  width: 100%;
  border-collapse: collapse;
}

thead th {
  background: linear-gradient(135deg, var(--blue), var(--green));
  color: var(--white);
  padding: 16px 20px;
  text-align: left;
  font-weight: 600;
  font-size: 14px;
  position: sticky;
  top: 0;
  white-space: nowrap;
}

th, td {
  padding: 14px 20px;
  border-bottom: 1px solid #eee;
  white-space: nowrap;
}

tbody tr:hover {
  background: #eef8f0;
}

tbody tr:nth-child(even) {
  background: #fafafa;
}

/* Status Badges */
.status-active {
  color: var(--green);
  font-weight: 600;
  background: rgba(0, 132, 61, 0.1);
  padding: 4px 12px;
  border-radius: 20px;
  display: inline-block;
}

.status-inactive {
  color: var(--red);
  font-weight: 600;
  background: rgba(198, 40, 40, 0.1);
  padding: 4px 12px;
  border-radius: 20px;
  display: inline-block;
}

/* Term Badge */
.term-badge {
  background: rgba(0, 114, 206, 0.1);
  color: var(--blue);
  padding: 4px 10px;
  border-radius: 6px;
  font-weight: 600;
  font-size: 13px;
}

/* Empty State */
.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: #666;
}

.empty-state i {
  font-size: 48px;
  color: #ddd;
  margin-bottom: 15px;
}

.empty-state h3 {
  font-size: 18px;
  margin-bottom: 10px;
  color: #888;
}

.empty-state p {
  color: #aaa;
}

/* Alert Popup */
.alert-popup {
  display: none;
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: var(--white);
  border-radius: 12px;
  padding: 30px 40px;
  text-align: center;
  z-index: 4000;
  box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2);
  min-width: 350px;
  border-top: 6px solid;
  animation: slideIn 0.3s ease;
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translate(-50%, -60px);
  }
  to {
    opacity: 1;
    transform: translate(-50%, -50%);
  }
}

.alert-popup.show {
  display: block;
}

.alert-popup.error {
  border-top-color: var(--red);
}

.alert-popup i {
  font-size: 48px;
  margin-bottom: 15px;
  display: block;
}

.alert-popup.error i {
  color: var(--red);
}

.alert-popup h3 {
  margin: 10px 0 5px;
  font-size: 20px;
  color: var(--dark);
}

.alert-popup p {
  margin: 0;
  color: #666;
  font-size: 14px;
}

/* Back Button */
.back-btn {
  background: linear-gradient(135deg, var(--blue), #005fa3);
  color: var(--white);
  border: none;
  padding: 12px 24px;
  border-radius: 8px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  gap: 10px;
  box-shadow: 0 4px 12px rgba(0, 114, 206, 0.3);
  text-decoration: none;
  font-size: 14px;
}

.back-btn:hover {
  transform: translateY(-3px);
  box-shadow: 0 6px 20px rgba(0, 114, 206, 0.4);
}

/* Responsive */
@media (max-width: 1024px) {
  .main-content {
    margin-left: 240px;
  }
}

@media (max-width: 768px) {
  .main-content {
    margin-left: 0 !important;
    padding: 80px 15px 20px;
  }
  
  .page-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 15px;
    padding: 15px;
  }
  
  .faculty-badge {
    margin-left: 0;
    margin-top: 5px;
  }
  
  .back-btn {
    align-self: stretch;
    justify-content: center;
  }
  
  .table-header {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
}

@media (max-width: 480px) {
  .page-header h1 {
    font-size: 20px;
  }
  
  .alert-popup {
    min-width: 280px;
    padding: 20px 25px;
  }
}

/* Scrollbar */
.table-wrapper::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

.table-wrapper::-webkit-scrollbar-track {
  background: #f1f1f1;
  border-radius: 4px;
}

.table-wrapper::-webkit-scrollbar-thumb {
  background: var(--green);
  border-radius: 4px;
}

.table-wrapper::-webkit-scrollbar-thumb:hover {
  background: var(--blue);
}
</style>
</head>
<body>

<?php include('../includes/header.php'); ?>

<div class="main-content">
  <!-- Page Header -->
  <div class="page-header">
    <h1>
      <i class="fas fa-calendar-alt"></i> Academic Terms
    </h1>
  </div>
  
  <!-- Info Message (Read-only) -->
 
  <!-- Table Header -->
  <div class="table-wrapper">
    <div class="table-header">
      <h3><i class="fas fa-list"></i> Academic Terms List</h3>
      <div class="results-count">
        <i class="fas fa-eye"></i> Showing <?= count($terms) ?> terms
      </div>
    </div>
    
    <!-- Terms Table -->
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Academic Year</th>
          <th>Term</th>
          <th>Start Date</th>
          <th>End Date</th>
          <th>Status</th>
          <th>Created At</th>
          <th>Last Updated</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($terms): ?>
          <?php foreach ($terms as $i => $t): ?>
          <tr>
            <td><strong><?= $i + 1 ?></strong></td>
            <td>
              <span class="term-badge">
                <i class="fas fa-calendar"></i> <?= htmlspecialchars($t['year_name']) ?>
              </span>
            </td>
            <td>
              <strong>Term <?= htmlspecialchars($t['term_name']) ?></strong>
            </td>
            <td>
              <i class="fas fa-play" style="color: var(--green); font-size: 12px; margin-right: 5px;"></i>
              <?= date('d M Y', strtotime($t['start_date'])) ?>
            </td>
            <td>
              <i class="fas fa-stop" style="color: var(--red); font-size: 12px; margin-right: 5px;"></i>
              <?= date('d M Y', strtotime($t['end_date'])) ?>
            </td>
            <td>
              <span class="status-<?= strtolower($t['status']) ?>">
                <i class="fas fa-<?= $t['status'] === 'active' ? 'check-circle' : 'pause-circle' ?>"></i>
                <?= ucfirst($t['status']) ?>
              </span>
            </td>
            <td>
              <span style="color: #666; font-size: 13px;">
                <i class="fas fa-clock"></i> <?= date('d M Y H:i', strtotime($t['created_at'] ?? 'now')) ?>
              </span>
            </td>
            <td>
              <span style="color: #666; font-size: 13px;">
                <i class="fas fa-sync-alt"></i> <?= date('d M Y H:i', strtotime($t['updated_at'] ?? 'now')) ?>
              </span>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="8">
              <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>No academic terms found</h3>
                <p>There are no academic terms available at the moment.</p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Alert Popup -->
<div id="popup" class="alert-popup <?= $type ?>">
  <i class="fas fa-exclamation-circle"></i>
  <h3>Error</h3>
  <p><?= htmlspecialchars($message) ?></p>
</div>

<script>
// Show alert if there's a message
<?php if (!empty($message)): ?>
  const popup = document.getElementById('popup');
  popup.classList.add('show');
  setTimeout(() => popup.classList.remove('show'), 3500);
<?php endif; ?>
</script>

<script src="../assets/js/sidebar.js"></script>

<?php include('../includes/footer.php'); ?>
</body>
</html>
<?php ob_end_flush(); ?>