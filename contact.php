<?php
/**
 * NexGen Solutions Contact Form Handler
 * 
 * This script processes contact form submissions with proper validation,
 * security measures, and email delivery.
 * 
 * Version: 2.0
 * Last Updated: February 2026
 */

// Prevent direct access
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    die('Direct access not permitted');
}

// Configuration
$config = [
    'receiving_email' => 'contact@nexgensolutions.com',
    'company_name' => 'NexGen Solutions',
    'from_email' => 'noreply@nexgensolutions.com',
    'enable_smtp' => false, // Set to true if using SMTP
    'max_message_length' => 5000,
    'required_fields' => ['name', 'email', 'subject', 'message']
];

// SMTP Configuration (uncomment and configure if needed)
/*
$smtp_config = [
    'host' => 'smtp.example.com',
    'port' => 587,
    'username' => 'your-email@example.com',
    'password' => 'your-password',
    'encryption' => 'tls' // or 'ssl'
];
*/

// Security Headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Initialize response
$response = [
    'success' => false,
    'message' => ''
];

try {
    // Rate Limiting - Simple IP-based check
    session_start();
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rate_limit_key = 'contact_form_' . md5($ip_address);
    
    if (isset($_SESSION[$rate_limit_key])) {
        $last_submission = $_SESSION[$rate_limit_key];
        $time_diff = time() - $last_submission;
        
        if ($time_diff < 60) { // 1 minute cooldown
            throw new Exception('Please wait ' . (60 - $time_diff) . ' seconds before submitting again.');
        }
    }
    
    // Validate Required Fields
    foreach ($config['required_fields'] as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            throw new Exception("Please fill in all required fields. Missing: {$field}");
        }
    }
    
    // Sanitize and Validate Input
    $name = sanitize_input($_POST['name']);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $subject = sanitize_input($_POST['subject']);
    $message = sanitize_input($_POST['message']);
    $phone = isset($_POST['phone']) ? sanitize_input($_POST['phone']) : '';
    $company = isset($_POST['company']) ? sanitize_input($_POST['company']) : '';
    
    // Validation Rules
    if (strlen($name) < 2 || strlen($name) > 100) {
        throw new Exception('Name must be between 2 and 100 characters.');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please provide a valid email address.');
    }
    
    if (strlen($subject) < 3 || strlen($subject) > 200) {
        throw new Exception('Subject must be between 3 and 200 characters.');
    }
    
    if (strlen($message) < 10) {
        throw new Exception('Message must be at least 10 characters long.');
    }
    
    if (strlen($message) > $config['max_message_length']) {
        throw new Exception('Message is too long. Maximum ' . $config['max_message_length'] . ' characters allowed.');
    }
    
    // Honeypot check (add a hidden field in your form named 'website')
    if (!empty($_POST['website'])) {
        // Likely a bot
        throw new Exception('Spam detected.');
    }
    
    // Simple spam keyword check
    $spam_keywords = ['viagra', 'cialis', 'casino', 'lottery', 'prize'];
    $message_lower = strtolower($message . ' ' . $subject);
    foreach ($spam_keywords as $keyword) {
        if (strpos($message_lower, $keyword) !== false) {
            throw new Exception('Your message contains prohibited content.');
        }
    }
    
    // Prepare Email
    $to = $config['receiving_email'];
    $email_subject = '[Contact Form] ' . $subject;
    
    // HTML Email Body
    $email_body = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #2c3e50; color: white; padding: 20px; text-align: center; }
        .content { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .field { margin-bottom: 15px; }
        .label { font-weight: bold; color: #2c3e50; }
        .value { margin-top: 5px; padding: 10px; background-color: white; border-left: 3px solid #3498db; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #777; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>' . htmlspecialchars($config['company_name']) . '</h2>
            <p>New Contact Form Submission</p>
        </div>
        
        <div class="content">
            <div class="field">
                <div class="label">Name:</div>
                <div class="value">' . htmlspecialchars($name) . '</div>
            </div>
            
            <div class="field">
                <div class="label">Email:</div>
                <div class="value"><a href="mailto:' . htmlspecialchars($email) . '">' . htmlspecialchars($email) . '</a></div>
            </div>';
    
    if (!empty($phone)) {
        $email_body .= '
            <div class="field">
                <div class="label">Phone:</div>
                <div class="value">' . htmlspecialchars($phone) . '</div>
            </div>';
    }
    
    if (!empty($company)) {
        $email_body .= '
            <div class="field">
                <div class="label">Company:</div>
                <div class="value">' . htmlspecialchars($company) . '</div>
            </div>';
    }
    
    $email_body .= '
            <div class="field">
                <div class="label">Subject:</div>
                <div class="value">' . htmlspecialchars($subject) . '</div>
            </div>
            
            <div class="field">
                <div class="label">Message:</div>
                <div class="value">' . nl2br(htmlspecialchars($message)) . '</div>
            </div>
            
            <div class="field">
                <div class="label">Submitted:</div>
                <div class="value">' . date('F j, Y, g:i a T') . '</div>
            </div>
            
            <div class="field">
                <div class="label">IP Address:</div>
                <div class="value">' . htmlspecialchars($ip_address) . '</div>
            </div>
        </div>
        
        <div class="footer">
            <p>This email was sent from the contact form on your website.</p>
            <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($config['company_name']) . '. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';

    // Plain Text Alternative
    $plain_text_body = "New Contact Form Submission\n\n";
    $plain_text_body .= "Name: {$name}\n";
    $plain_text_body .= "Email: {$email}\n";
    if (!empty($phone)) $plain_text_body .= "Phone: {$phone}\n";
    if (!empty($company)) $plain_text_body .= "Company: {$company}\n";
    $plain_text_body .= "Subject: {$subject}\n\n";
    $plain_text_body .= "Message:\n{$message}\n\n";
    $plain_text_body .= "Submitted: " . date('F j, Y, g:i a T') . "\n";
    $plain_text_body .= "IP Address: {$ip_address}\n";
    
    // Email Headers
    $boundary = md5(time());
    $headers = [];
    $headers[] = "From: {$config['company_name']} <{$config['from_email']}>";
    $headers[] = "Reply-To: {$name} <{$email}>";
    $headers[] = "MIME-Version: 1.0";
    $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
    $headers[] = "X-Mailer: PHP/" . phpversion();
    
    // Combine Plain Text and HTML
    $full_message = "--{$boundary}\r\n";
    $full_message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $full_message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $full_message .= $plain_text_body . "\r\n";
    $full_message .= "--{$boundary}\r\n";
    $full_message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $full_message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $full_message .= $email_body . "\r\n";
    $full_message .= "--{$boundary}--";
    
    // Send Email
    if ($config['enable_smtp']) {
        // Use PHPMailer or similar SMTP library here
        // This is a placeholder - you'll need to implement SMTP sending
        throw new Exception('SMTP is configured but not implemented. Please use mail() function or implement PHPMailer.');
    } else {
        // Use PHP mail() function
        $mail_sent = mail($to, $email_subject, $full_message, implode("\r\n", $headers));
        
        if (!$mail_sent) {
            throw new Exception('Failed to send email. Please try again later or contact us directly.');
        }
    }
    
    // Update rate limit
    $_SESSION[$rate_limit_key] = time();
    
    // Log submission (optional - create logs directory first)
    log_submission($name, $email, $subject, $ip_address);
    
    // Success Response
    $response['success'] = true;
    $response['message'] = 'Thank you for contacting us! We will get back to you within 24 hours.';
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

// Output JSON Response
echo json_encode($response);
exit;

/**
 * Sanitize user input
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Log submission to file (optional)
 */
function log_submission($name, $email, $subject, $ip) {
    $log_dir = __DIR__ . '/logs';
    
    // Only log if directory exists
    if (!is_dir($log_dir)) {
        return; // Skip logging if directory doesn't exist
    }
    
    $log_file = $log_dir . '/contact_submissions_' . date('Y-m') . '.log';
    $log_entry = sprintf(
        "[%s] Name: %s | Email: %s | Subject: %s | IP: %s\n",
        date('Y-m-d H:i:s'),
        $name,
        $email,
        $subject,
        $ip
    );
    
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}
?>
