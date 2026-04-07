// Function to save email log
function saveEmailLog($student_id, $recipient_email, $subject, $message, $message_type, $absence_count = 0, $status = 'sent', $error_message = null) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO email_logs 
            (student_id, recipient_email, subject, message, message_type, absence_count, status, error_message, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $student_id,
            $recipient_email,
            $subject,
            $message,
            $message_type,
            $absence_count,
            $status,
            $error_message
        ]);
        
        return $pdo->lastInsertId();
    } catch (Exception $e) {
        error_log("Error saving email log: " . $e->getMessage());
        return false;
    }
}

// Function to get email template
function getEmailTemplate($template_type) {
    global $pdo;
    
    $stmt = $pdo->prepare("
        SELECT * FROM email_templates 
        WHERE template_type = ? AND status = 'active' 
        LIMIT 1
    ");
    $stmt->execute([$template_type]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Function to replace template variables
function replaceTemplateVariables($template, $variables) {
    $text = $template;
    foreach ($variables as $key => $value) {
        $text = str_replace('{' . $key . '}', $value, $text);
    }
    return $text;
}