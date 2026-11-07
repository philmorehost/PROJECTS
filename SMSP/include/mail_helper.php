<?php
// HTML Mail Sender Wrapper helper
class MailHelper {
    public static function sendHTML($to, $subject, $body, $cc = '') {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        
        $fromEmail = defined('PLATFORM_SUPPORT_EMAIL') ? PLATFORM_SUPPORT_EMAIL : 'support@schoolapp.ng';
        $fromName = defined('PLATFORM_NAME') ? PLATFORM_NAME : 'SchoolApp';
        
        $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "Reply-To: {$fromEmail}\r\n";
        
        if (!empty($cc)) {
            $headers .= "Cc: {$cc}\r\n";
        }
        
        // Use PHP's built-in mail function
        return @mail($to, $subject, $body, $headers);
    }
}
?>
