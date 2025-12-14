<?php
// verify_email.php

// These lines are CRITICAL for using the class aliases inside the method.
// We are NOT including the PHPMailer files here, just defining the aliases.
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


class EmailVerification
{
    public function getPinEmailBody($recipientName, $pinCode) 
    {
        return "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background-color: #f5f5f5;
                    padding: 20px;
                }

                .email-container {
                    max-width: 600px;
                    margin: 0 auto;
                    background-color: #ffffff;
                    border-radius: 10px;
                    overflow: hidden;
                    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                }

                .header {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    padding: 40px 20px;
                    text-align: center;
                    color: white;
                }

                .header h1 {
                    font-size: 24px;
                    margin-bottom: 10px;
                }

                .header p {
                    font-size: 14px;
                    opacity: 0.9;
                }

                .content {
                    padding: 40px 30px;
                    text-align: center;
                }

                .greeting {
                    font-size: 18px;
                    color: #333;
                    margin-bottom: 20px;
                }

                .message {
                    font-size: 14px;
                    color: #666;
                    line-height: 1.6;
                    margin-bottom: 30px;
                }

                .pin-container {
                    background: #f8f9fa;
                    border: 2px dashed #667eea;
                    border-radius: 10px;
                    padding: 30px;
                    margin: 30px 0;
                }

                .pin-label {
                    font-size: 12px;
                    color: #666;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                    margin-bottom: 15px;
                }

                .pin-code {
                    font-size: 48px;
                    font-weight: bold;
                    color: #667eea;
                    letter-spacing: 8px;
                    font-family: 'Courier New', monospace;
                }

                .warning {
                    background: #fff3cd;
                    border-left: 4px solid #ffc107;
                    padding: 15px;
                    margin: 20px 0;
                    text-align: left;
                }

                .warning-title {
                    font-weight: bold;
                    color: #856404;
                    margin-bottom: 5px;
                    font-size: 14px;
                }

                .warning-text {
                    color: #856404;
                    font-size: 13px;
                    line-height: 1.5;
                }

                .expiry {
                    font-size: 13px;
                    color: #dc3545;
                    margin-top: 15px;
                    font-weight: 600;
                }

                .footer {
                    background: #f8f9fa;
                    padding: 20px;
                    text-align: center;
                    border-top: 1px solid #e0e0e0;
                }

                .footer p {
                    font-size: 12px;
                    color: #999;
                    line-height: 1.5;
                }
            </style>
        </head>
        <body>
            <div class='email-container'>
                <div class='header'>
                    <div style='font-size: 48px; margin-bottom: 10px;'>🔐</div>
                    <h1>Verification Code</h1>
                    <p>Business Permit System</p>
                </div>

                <div class='content'>
                    <p class='greeting'>Hello, <strong>{$recipientName}</strong>!</p>

                    <p class='message'>
                        We received a request to verify your account. 
                        Please use the PIN code below to complete your verification.
                    </p>

                    <div class='pin-container'>
                        <div class='pin-label'>Your Verification PIN</div>
                        <div class='pin-code'>{$pinCode}</div>
                    </div>

                    <div class='warning'>
                        <div class='warning-title'>Security Notice</div>
                        <div class='warning-text'>
                            • Never share this PIN with anyone<br>
                            • Our team will never ask for your PIN<br>
                            • If you didn't request this code, please ignore this email
                        </div>
                    </div>

                    <p class='message'>
                        If you have any questions, please contact our support team.
                    </p>
                </div>

                <div class='footer'>
                    <p>
                        <strong>Business Permits and Licensing Department</strong><br>
                        This is an automated email. Please do not reply to this message.<br>
                        © 2025 Business Permit System. All rights reserved.
                    </p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    public function verifyEmail($to_gmail, $full_name, &$pinCode)
    {
        // create a random pin to send to the gmail
        $pinCode = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

        // PHPMailer is now available because it was included in the calling script (e.g., register.php)
        $mail = new PHPMailer(true); 

        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; // free host domain with 500 below limit sends
        $mail->SMTPAuth   = true;
        $mail->Username   = 'email@gmail.com';      // Gmail address
        $mail->Password   = 'secret';          // App password (not regular password!)
        $mail->SMTPSecure = 'ssl';
        $mail->Port       = 465;

        // Recipients
        $mail->setFrom('email@gmail.com', 'quiz_data');
        $mail->addAddress($to_gmail, $full_name);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Email Verification';
        $mail->Body = $this->getPinEmailBody($full_name, $pinCode);
        $mail->AltBody = "Hello {$full_name}, Your verification PIN is: {$pinCode}";

        $mail->send();
    }
}
