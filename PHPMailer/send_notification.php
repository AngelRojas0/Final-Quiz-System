<?php
// Use PHPMailer classes
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// --- CRITICAL FIX 1: Corrected PHPMailer Paths ---
// Assumes PHPMailer files are in a standard 'PHPMailer/' subfolder.
require 'PHPMailer/Exception.php'; 
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php'; 


// =================================================================
// NEW FUNCTION: Send Verification Email with Link
// =================================================================

/**
 * Sends a verification email with a link containing a unique token.
 * @param string $userEmail The recipient's email address.
 * @param string $userName The recipient's name.
 * @param string $token The unique verification token generated for the user.
 * @param string $baseUrl The base URL of your system (e.g., 'https://yourdomain.com').
 * @return bool True on success, False on failure.
 */
function sendVerificationEmail($userEmail, $userName, $token, $baseUrl)
{
    $mail = new PHPMailer(true);

    // --- Configuration (Adjust as needed) ---
    // Using the setup from your existing send_notification.php
    $host = 'smtp.gmail.com';     
    $username = 'angelnicole331203@gmail.com'; 
    $password = 'YOUR_GMAIL_APP_PASSWORD'; // !!! REPLACE THIS with your App Password !!!
    $port = 587;
    $secure = PHPMailer::ENCRYPTION_STARTTLS;

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        $mail->SMTPSecure = $secure;
        $mail->Port       = $port;
        
        // Recipients
        $mail->setFrom($username, 'Your Learning System');
        $mail->addAddress($userEmail, $userName);
        
        // The link the user clicks to verify their email
        $verificationLink = "{$baseUrl}/verify_email.php?email=" . urlencode($userEmail) . "&token=" . $token;

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Email Address for Your Learning System';
        
        $mail->Body    = "
            <html>
            <body>
                <h1>Welcome to Your Learning System!</h1>
                <p>Hello **{$userName}**, </p>
                <p>Thank you for registering. Please click the button below to **verify your email address** and activate your account:</p>
                <p style='text-align: center; margin: 20px 0;'>
                    <a href='{$verificationLink}' style='background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                        Activate My Account
                    </a>
                </p>
                <p>If the button above doesn't work, copy and paste the following link into your web browser:</p>
                <p><small>{$verificationLink}</small></p>
                <p>This link will expire in 1 hour.</p>
                <p>Regards,<br>Your System Team</p>
            </body>
            </html>
        ";
        
        $mail->AltBody = "Hello {$userName}! Please visit the following link to verify your email address: {$verificationLink}";

        $mail->send();
        return true;

    } catch (Exception $e) {
        // Log the error for debugging on your server
        error_log("Verification email failed for {$userEmail}. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}