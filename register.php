<?php
// register.php

require_once 'config.php';

// --- PHPMailer Manual Setup ---
require_once 'PHPMailer/Exception.php'; 
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';

// Include your custom class file 
require_once 'verify_email.php'; 

// Use the namespace aliases
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (is_logged_in()) {
    redirect(get_user_role() === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php');
}

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $name = trim($_POST['name'] ?? '');
    
    $display_name = !empty($first_name) ? trim("{$first_name} {$last_name}") : $name;
    
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'student';

    if (empty($display_name) || empty($email) || empty($pass)) {
        $error = 'All fields are required.';
    }

    if (!$error) {
        // 1. Check for existing user
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $existing_user_count = $stmt->fetchColumn();

        if ($existing_user_count > 0) {
            $error = 'This email address is already registered.';
        }
    }

    if (!$error) {
        // 2. Insert the new user into the database (is_active is defaulted to 0/unverified)
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
        
        try {
            $pdo->beginTransaction();
            $stmt->execute([$display_name, $email, $hash, $role]);
            
            // --- START EMAIL VERIFICATION LOGIC ---
            
            $emailService = new EmailVerification(); // Class from verify_email.php
            $pinCode = ''; 
            
            try {
                // CALL THE CLASS METHOD
                $emailService->verifyEmail($email, $display_name, $pinCode);
                
                // 3. Store the PIN in the database
                $v_stmt = $pdo->prepare("INSERT INTO verification_codes (email, pin_code, created_at) VALUES (?, ?, NOW()) 
                                         ON DUPLICATE KEY UPDATE pin_code=VALUES(pin_code), created_at=NOW()");
                $v_stmt->execute([$email, $pinCode]);
                
                $pdo->commit();

                // 4. Set a success message and redirect to the PIN verification page
                $message = 'Registration successful! A verification PIN has been sent to your email address.';
                redirect('verify_code.php?email=' . urlencode($email)); 
                
            } catch (Exception $e) {
                $pdo->rollBack();
                // If email failed, we still have a user record, but roll back the pin insertion
                error_log("Email sending failed for {$email}. Mailer Error: {$e->getMessage()}");
                $error = 'Registration was successful, but the verification email could not be sent. Please contact support.';
                // Redirect to login with error, user must contact support/request new PIN
                redirect('login.php?error=' . urlencode($error)); 
            }
            
            // --- END EMAIL VERIFICATION LOGIC ---

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Database Error during user registration: " . $e->getMessage()); 
            $error = "An unexpected error occurred during registration.";
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register - Quiz System</title>
    <link rel="stylesheet" href="styles.css">
    <script>
    function togglePassword(fieldId) {
        const field = document.getElementById(fieldId);
        const toggle = field.nextElementSibling;
        if (field.type === 'password') {
            field.type = 'text';
            toggle.innerHTML = '✖';
        } else {
            field.type = 'password';
            toggle.innerHTML = '👁';
        }
    }
    </script>
</head>
<body class="center-page">
    <div class="container">
        <div class="card">
            <h1>📝 Join Quiz System</h1>
            <p>Create your account to start learning.</p>
            
            <?php if ($error): ?><div class="msg err"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($message): ?><div class="msg success"><?php echo e($message); ?></div><?php endif; ?>

            <form method="post">
                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" required>
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" required>
                </div>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
<div class="password-field" style="position: relative;">
                        <input type="password" id="password" name="password" required minlength="6" style="padding-right: 40px;">
                        <button type="button" class="password-toggle" onclick="togglePassword('password')" tabindex="-1">👁</button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        <option value="student">Student</option>
                        <option value="admin">Admin (Teacher)</option>
                    </select>
                </div>
                
                <div style="text-align: center; margin-top: 1rem;">
                    <button type="submit" class="button primary small">Create Account</button>
                </div>
            </form>
            <div class="actions">
                <a href="login.php" class="button secondary full-width">Have account? Sign in</a>
            </div>
        </div>
    </div>
</body>
</html>
