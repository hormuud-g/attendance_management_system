<?php
session_start();
require_once(__DIR__ . '/../config/db_connect.php');

$parent_id = intval($_GET['parent_id'] ?? 0);
if (!$parent_id) {
    echo '<p class="text-muted">Invalid request</p>';
    exit;
}

$role = strtolower($_SESSION['user']['role'] ?? '');
$user_campus_id = $_SESSION['user']['campus_id'] ?? null;

// Get children with campus info
$sql = "SELECT ps.*, 
               s.student_id, s.full_name, s.reg_no, s.status AS student_status,
               s.campus_id AS student_campus_id,
               c.campus_name, c.campus_code
        FROM parent_student ps
        JOIN students s ON s.student_id = ps.student_id
        LEFT JOIN campus c ON s.campus_id = c.campus_id
        WHERE ps.parent_id = ?";
        
if ($role !== 'super_admin' && $user_campus_id) {
    $sql .= " AND s.campus_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$parent_id, $user_campus_id]);
} else {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$parent_id]);
}

$children = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($children)) {
    echo '<p class="text-muted">No children linked to this parent</p>';
    exit;
}
?>
<div class="children-details">
    <h5>Linked Students (<?= count($children) ?>)</h5>
    <table class="children-table" style="width:100%;border-collapse:collapse;">
        <thead>
            <tr style="background:#f8f9fa;">
                <th style="padding:10px;text-align:left;">Student</th>
                <th style="padding:10px;text-align:left;">Reg No</th>
                <th style="padding:10px;text-align:left;">Campus</th>
                <th style="padding:10px;text-align:left;">Relationship</th>
                <th style="padding:10px;text-align:left;">Status</th>
                <th style="padding:10px;text-align:left;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($children as $child): ?>
            <tr style="border-bottom:1px solid #eee;">
                <td style="padding:10px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <i class="fa fa-user-graduate"></i>
                        <div>
                            <strong><?= htmlspecialchars($child['full_name']) ?></strong>
                        </div>
                    </div>
                </td>
                <td style="padding:10px;"><?= htmlspecialchars($child['reg_no']) ?></td>
                <td style="padding:10px;">
                    <?php if($child['campus_name']): ?>
                    <span style="background:#e3f2fd;padding:4px 8px;border-radius:4px;font-size:12px;">
                        <?= htmlspecialchars($child['campus_name']) ?>
                    </span>
                    <?php else: ?>
                    <span style="color:#666;">N/A</span>
                    <?php endif; ?>
                </td>
                <td style="padding:10px;">
                    <span style="background:#f5f5f5;padding:4px 8px;border-radius:4px;font-size:12px;">
                        <?= $child['relation_type'] ?>
                    </span>
                    <?php if($child['is_primary'] == 'yes'): ?>
                    <span style="background:#e8f5e9;padding:4px 8px;border-radius:4px;font-size:12px;margin-left:5px;">
                        Primary
                    </span>
                    <?php endif; ?>
                </td>
                <td style="padding:10px;">
                    <span style="background:<?= $child['student_status']=='active'?'#e8f5e9':'#ffebee' ?>;color:<?= $child['student_status']=='active'?'#2e7d32':'#c62828' ?>;padding:4px 8px;border-radius:4px;font-size:12px;">
                        <?= ucfirst($child['student_status']) ?>
                    </span>
                </td>
                <td style="padding:10px;">
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="relation_id" value="<?= $child['relation_id'] ?>">
                        <input type="hidden" name="action" value="unlink_student">
                        <button type="submit" style="background:none;border:none;color:#c62828;cursor:pointer;" 
                                onclick="return confirm('Unlink this student?')" title="Unlink">
                            <i class="fa fa-unlink"></i> Unlink
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>