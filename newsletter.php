<?php
/**
 * NexGen Solutions Newsletter Subscription Handler
 * 
 * This script processes newsletter subscription requests with proper validation,
 * security measures, and optional database storage.
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
    'receiving_email' => 'newsletter@nexgensolutions.com',
    'company_name' => 'NexGen Solutions',
    'from_email' => 'noreply@nexgensolutions.com',
    'admin_notification' => true, // Send notification to admin
    'send_confirmation' => true,  // Send confirmation email to subscriber
    'use_database' => false,      // Set to true if you want to store in database
    'require_double_optin' => false // Set to true for double opt-in confirmation
];

// Database Configuration (if using database storage)
$db_config = [
    'host' => 'localhost',
    'database' => 'nexgen_db',
    'username' => 'db_user',
    'password' => 'db_password',
    'table' => 'newsletter_subscribers'
];

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
    $rate_limit_key = 'newsletter_' . md5($ip_address);
    
    if (isset($_SESSION[$rate_limit_key])) {
        $last_submission = $_SESSION[$rate_limit_key];
        $time_diff = time() - $last_submission;
        
        if ($time_diff < 30) { // 30 seconds cooldown
            throw new Exception('Please wait ' . (30 - $time_diff) . ' seconds before subscribing again.');
        }
    }
    
    // Validate Email Field
    if (!isset($_POST['email']) || empty(trim($_POST['email']))) {
        throw new Exception('Please provide an email address.');
    }
    
    // Sanitize and Validate Email
    $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please provide a valid email address.');
    }
    
    // Check for disposable/temporary email domains
    $disposable_domains = ['tempmail.com', 'throwaway.email', '10minutemail.com', 'guerrillamail.com'];
    $email_domain = substr(strrchr($email, "@"), 1);
    
    if (in_array(strtolower($email_domain), $disposable_domains)) {
        throw new Exception('Disposable email addresses are not allowed.');
    }
    
    // Honeypot check (add a hidden field in your form named 'website')
    if (!empty($_POST['website'])) {
        // Likely a bot
        throw new Exception('Spam detected.');
    }
    
    // Check if already subscribed (if using database)
    if ($config['use_database']) {
        if (is_already_subscribed($email, $db_config)) {
            throw new Exception('This email is already subscribed to our newsletter.');
        }
    }
    
    // Generate subscription token for double opt-in
    $subscription_token = bin2hex(random_bytes(32));
    
    // Store in database if enabled
    if ($config['use_database']) {
        store_subscriber($email, $ip_address, $subscription_token, $db_config);
    }
    
    // Send confirmation email to subscriber
    if ($config['send_confirmation']) {
        send_confirmation_email($email, $subscription_token, $config);
    }
    
    // Send notification to admin
    if ($config['admin_notification']) {
        send_admin_notification($email, $ip_address, $config);
    }
    
    // Log subscription
    log_subscription($email, $ip_address);
    
    // Update rate limit
    $_SESSION[$rate_limit_key] = time();
    
    // Success Response
    $response['success'] = true;
    if ($config['require_double_optin']) {
        $response['message'] = 'Thank you for subscribing! Please check your email to confirm your subscription.';
    } else {
        $response['message'] = 'Thank you for subscribing! You will receive our latest updates and insights.';
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

// Output JSON Response
echo json_encode($response);
exit;

/**
 * Send confirmation email to subscriber
 */
function send_confirmation_email($email, $token, $config) {
    $subject = "Welcome to {$config['company_name']} Newsletter!";
    
    $confirmation_link = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . 
                        "://{$_SERVER['HTTP_HOST']}/confirm-subscription.php?token={$token}";
    
    // HTML Email Body
    $email_body = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; background-color: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 20px auto; background-color: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 40px 20px; text-align: center; }
        .header h1 { margin: 0; font-size: 28px; }
        .content { padding: 40px 30px; }
        .content h2 { color: #2c3e50; margin-top: 0; }
        .content p { color: #555; font-size: 16px; }
        .button { display: inline-block; padding: 15px 30px; background-color: #667eea; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
        .button:hover { background-color: #5568d3; }
        .benefits { background-color: #f9f9f9; padding: 20px; border-radius: 5px; margin: 20px 0; }
        .benefits ul { margin: 10px 0; padding-left: 20px; }
        .benefits li { margin: 8px 0; color: #555; }
        .footer { background-color: #2c3e50; color: #bbb; padding: 20px; text-align: center; font-size: 14px; }
        .footer a { color: #667eea; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Welcome to ' . htmlspecialchars($config['company_name']) . '!</h1>
        </div>
        
        <div class="content">
            <h2>Thank you for subscribing!</h2>
            <p>We\'re excited to have you join our community of forward-thinking professionals who are passionate about technology and innovation.</p>';
    
    if ($config['require_double_optin']) {
        $email_body .= '
            <p>To complete your subscription and start receiving our newsletter, please confirm your email address by clicking the button below:</p>
            <div style="text-align: center;">
                <a href="' . htmlspecialchars($confirmation_link) . '" class="button">Confirm Subscription</a>
            </div>
            <p style="font-size: 14px; color: #777;">If the button doesn\'t work, copy and paste this link into your browser:<br>
            <a href="' . htmlspecialchars($confirmation_link) . '">' . htmlspecialchars($confirmation_link) . '</a></p>';
    }
    
    $email_body .= '
            <div class="benefits">
                <h3 style="margin-top: 0; color: #2c3e50;">What to Expect:</h3>
                <ul>
                    <li><strong>Weekly Insights:</strong> Expert articles on digital transformation, cloud computing, and AI</li>
                    <li><strong>Exclusive Resources:</strong> Whitepapers, case studies, and guides</li>
                    <li><strong>Industry Trends:</strong> Stay ahead with the latest technology news</li>
                    <li><strong>Special Offers:</strong> Early access to webinars and events</li>
                    <li><strong>Best Practices:</strong> Actionable tips from industry leaders</li>
                </ul>
            </div>
            
            <p>You can unsubscribe at any time by clicking the link at the bottom of any email we send you.</p>
            
            <p>If you have any questions, feel free to reply to this email or contact us at <a href="mailto:' . $config['receiving_email'] . '">' . $config['receiving_email'] . '</a>.</p>
            
            <p style="margin-top: 30px;">Best regards,<br><strong>The ' . htmlspecialchars($config['company_name']) . ' Team</strong></p>
        </div>
        
        <div class="footer">
            <p>&copy; ' . date('Y') . ' ' . htmlspecialchars($config['company_name']) . '. All rights reserved.</p>
            <p>2847 Maple Avenue, Los Angeles, CA 90210</p>
            <p><a href="#">Unsubscribe</a> | <a href="#">Privacy Policy</a></p>
        </div>
    </div>
</body>
</html>';

    // Plain Text Alternative
    $plain_text_body = "Welcome to {$config['company_name']} Newsletter!\n\n";
    $plain_text_body .= "Thank you for subscribing!\n\n";
    
    if ($config['require_double_optin']) {
        $plain_text_body .= "To complete your subscription, please confirm your email address by visiting:\n";
        $plain_text_body .= $confirmation_link . "\n\n";
    }
    
    $plain_text_body .= "What to Expect:\n";
    $plain_text_body .= "- Weekly insights on digital transformation and technology\n";
    $plain_text_body .= "- Exclusive resources and case studies\n";
    $plain_text_body .= "- Industry trends and best practices\n";
    $plain_text_body .= "- Special offers and early event access\n\n";
    $plain_text_body .= "You can unsubscribe at any time.\n\n";
    $plain_text_body .= "Best regards,\nThe {$config['company_name']} Team\n";
    
    // Email Headers
    $boundary = md5(time());
    $headers = [];
    $headers[] = "From: {$config['company_name']} <{$config['from_email']}>";
    $headers[] = "Reply-To: {$config['receiving_email']}";
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
    return mail($email, $subject, $full_message, implode("\r\n", $headers));
}

/**
 * Send notification to admin about new subscriber
 */
function send_admin_notification($email, $ip, $config) {
    $subject = "New Newsletter Subscription";
    
    $message = "New newsletter subscription:\n\n";
    $message .= "Email: {$email}\n";
    $message .= "IP Address: {$ip}\n";
    $message .= "Timestamp: " . date('F j, Y, g:i a T') . "\n";
    $message .= "User Agent: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown') . "\n";
    
    $headers = "From: {$config['from_email']}\r\n";
    $headers .= "Reply-To: {$config['from_email']}\r\n";
    
    return mail($config['receiving_email'], $subject, $message, $headers);
}

/**
 * Store subscriber in database
 */
function store_subscriber($email, $ip, $token, $db_config) {
    try {
        $pdo = new PDO(
            "mysql:host={$db_config['host']};dbname={$db_config['database']}",
            $db_config['username'],
            $db_config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $sql = "INSERT INTO {$db_config['table']} 
                (email, ip_address, subscription_token, subscribed_at, status) 
                VALUES (:email, :ip, :token, NOW(), 'pending')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':email' => $email,
            ':ip' => $ip,
            ':token' => $token
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if email is already subscribed
 */
function is_already_subscribed($email, $db_config) {
    try {
        $pdo = new PDO(
            "mysql:host={$db_config['host']};dbname={$db_config['database']}",
            $db_config['username'],
            $db_config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $sql = "SELECT COUNT(*) FROM {$db_config['table']} 
                WHERE email = :email AND status IN ('active', 'pending')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':email' => $email]);
        
        return $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}

/**
 * Log subscription to file
 */
function log_subscription($email, $ip) {
    $log_dir = __DIR__ . '/logs';
    
    // Only log if directory exists
    if (!is_dir($log_dir)) {
        return;
    }
    
    $log_file = $log_dir . '/newsletter_subscriptions_' . date('Y-m') . '.log';
    $log_entry = sprintf(
        "[%s] Email: %s | IP: %s\n",
        date('Y-m-d H:i:s'),
        $email,
        $ip
    );
    
    @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

/**
 * Database table creation SQL (for reference)
 * 
 * CREATE TABLE newsletter_subscribers (
 *     id INT AUTO_INCREMENT PRIMARY KEY,
 *     email VARCHAR(255) NOT NULL UNIQUE,
 *     ip_address VARCHAR(45),
 *     subscription_token VARCHAR(64),
 *     subscribed_at DATETIME NOT NULL,
 *     confirmed_at DATETIME,
 *     status ENUM('pending', 'active', 'unsubscribed') DEFAULT 'pending',
 *     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *     INDEX idx_email (email),
 *     INDEX idx_status (status)
 * ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
 */
?>
