<?php
/*******************************************************************************************
 * AUDIT HELPER — Handles inserting logs into audit_log table
 * Author: ChatGPT 2025 | PHP 8.2 | PDO Secure
 *******************************************************************************************/
if (!function_exists('add_audit_log')) {
  function add_audit_log($pdo, $user_id, $action, $description) {
    try {
      // Get IP Address
      $ip = $_SERVER['REMOTE_ADDR'] ?? 'N/A';
      if ($ip === '::1') $ip = '127.0.0.1';

      // Get User Agent
      $agent = $_SERVER['HTTP_USER_AGENT'] ?? 'N/A';

      $stmt = $pdo->prepare("
        INSERT INTO audit_log (user_id, action_type, description, ip_address, user_agent)
        VALUES (:uid, :act, :desc, :ip, :agent)
      ");
      $stmt->execute([
        ':uid' => $user_id,
        ':act' => $action,
        ':desc' => $description,
        ':ip' => $ip,
        ':agent' => substr($agent, 0, 250)
      ]);
    } catch (PDOException $e) {
      error_log('Audit Log Error: ' . $e->getMessage());
    }
  }
}
?>
