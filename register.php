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
</head>
<body class="center-page">
    <div class="container">
        <h1>📝 Register</h1>
        
        <?php if ($error): ?><div class="msg err"><?php echo e($error); ?></div><?php endif; ?>
        <?php if ($message): ?><div class="msg success"><?php echo e($message); ?></div><?php endif; ?>

        <form method="post">
            <label>First Name <input type="text" name="first_name" required></label>
            <label>Last Name <input type="text" name="last_name" required></label>
            <label>Email Address <input type="email" name="email" required></label>
            <label>Password <input type="password" name="password" required></label>
            <label>Role
                <select name="role">
                    <option value="student">Student</option>
                    <option value="admin">Admin (Teacher)</option>
                </select>
            </label>
            
            <div class="actions">
                <button type="submit" class="button success">Register</button>
            </div>
            
            <p style="text-align: center; margin-top: 15px;">
                Already have an account? <a href="login.php">Login here</a>.
            </p>
        </form>
    </div>
</body>
</html>