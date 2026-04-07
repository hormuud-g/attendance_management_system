<?php
session_start();
require_once(__DIR__ . '/../config/db_connect.php');

$parent_id = intval($_GET['parent_id'] ?? 0);
if (!$parent_id) {
    echo '<p style="color:#666;text-align:center;">Invalid request</p>';
    exit;
}

$role = strtolower($_SESSION['user']['role'] ?? '');
$user_campus_id = $_SESSION['user']['campus_id'] ?? null;

// Get children with campus info
$sql = "SELECT s.*, ps.relation_type, ps.is_primary, c.campus_name, c.campus_code
        FROM parent_student ps
        JOIN students s ON s.student_id = ps.student_id
        LEFT JOIN campus c ON s.campus_id = c.campus_id
        WHERE ps.parent_id = ?";
        
$params = [$parent_id];

if ($role === 'campus_admin' && $user_campus_id) {
    $sql .= " AND s.campus_id = ?";
    $params[] = $user_campus_id;
}

$sql .= " ORDER BY ps.is_primary DESC, s.full_name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$children = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($children)) {
    echo '<p style="color:#666;text-align:center;">No children found for this parent</p>';
    exit;
}
?>
<div style="display: grid; gap: 12px;">
<?php foreach($children as $child): ?>
<div style="padding: 15px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #0072CE;">
    <div style="display: flex; justify-content: space-between; align-items: start;">
        <div>
            <h4 style="margin: 0 0 8px 0; color: #333;">
                <i class="fas fa-user-graduate"></i> <?= htmlspecialchars($child['full_name']) ?>
                <?php if($child['is_primary'] == 'yes'): ?>
                <span style="background: #00843D; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px; margin-left: 8px;">
                    Primary
                </span>
                <?php endif; ?>
            </h4>
            <div style="display: flex; gap: 15px; font-size: 13px; color: #666;">
                <span><i class="fas fa-id-card"></i> <?= htmlspecialchars($child['reg_no']) ?></span>
                <span><i class="fas fa-link"></i> <?= htmlspecialchars($child['relation_type']) ?></span>
                <span style="color: <?= $child['status']=='active'?'#00843D':'#C62828' ?>">
                    <i class="fas fa-circle"></i> <?= ucfirst($child['status']) ?>
                </span>
            </div>
        </div>
        <div>
            <?php if(!empty($child['campus_name'])): ?>
            <span style="background: #e3f2fd; color: #1976d2; padding: 4px 10px; border-radius: 12px; font-size: 12px;">
                <i class="fas fa-university"></i> <?= htmlspecialchars($child['campus_name']) ?>
            </span>
            <?php endif; ?>
        </div>
    </div>
    <?php if($child['phone_number'] || $child['email']): ?>
    <div style="margin-top: 10px; font-size: 12px; color: #666;">
        <?php if($child['phone_number']): ?>
        <span style="margin-right: 15px;"><i class="fas fa-phone"></i> <?= htmlspecialchars($child['phone_number']) ?></span>
        <?php endif; ?>
        <?php if($child['email']): ?>
        <span><i class="fas fa-envelope"></i> <?= htmlspecialchars($child['email']) ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>