<?php

require_once 'config.php';

$error = '';
$message = '';

// Get the email from the URL (where it was passed by register.php)
$email = trim($_GET['email'] ?? '');

if (empty($email)) {
    redirect('register.php'); // Redirect back if no email is provided
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pin_code = trim($_POST['pin_code'] ?? '');

    if (empty($email) || empty($pin_code)) {
        $error = 'Email and PIN code are required.';
    }

    if (!$error) {
        // 1. Check if the PIN exists and is valid (not expired)
        $stmt = $pdo->prepare("SELECT pin_code FROM verification_codes WHERE email = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
        $stmt->execute([$email]);
        $stored_pin = $stmt->fetchColumn();

        if ($stored_pin && $stored_pin === $pin_code) {
            
            // 2. PIN is correct and valid: Activate the user and delete the PIN
            $pdo->beginTransaction();
            try {
                // Set the user's status to active (assuming you have an 'is_active' column in your 'users' table)
                $user_stmt = $pdo->prepare("UPDATE users SET is_active = 1 WHERE email = ?");
                $user_stmt->execute([$email]);
                
                // Delete the verification record
                $delete_stmt = $pdo->prepare("DELETE FROM verification_codes WHERE email = ?");
                $delete_stmt->execute([$email]);
                
                $pdo->commit();

                //Redirect to login page
                $message = 'Email verification successful! You can now log in.';
                redirect('login.php?message=' . urlencode($message));
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Verification Error: " . $e->getMessage()); 
                $error = "An error occurred during account activation.";
            }

        } else {
            $error = 'Invalid or expired PIN code. Please try again or re-register.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code - Quiz System</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body class="center-page">
    <div class="container">
        <div class="card">
            <h1>🔐 Verify Email</h1>
            <p>Check <?php echo e($email); ?> for your 6-digit PIN</p>
            
            <?php if ($error): ?><div class="msg err"><?php echo e($error); ?></div><?php endif; ?>
            <?php if ($message): ?><div class="msg success"><?php echo e($message); ?></div><?php endif; ?>

            <form method="post">
                <input type="hidden" name="email" value="<?php echo e($email); ?>">
                <div class="form-group">
                    <label for="pin_code">Verification PIN</label>
                    <input type="text" id="pin_code" name="pin_code" required maxlength="6" style="text-align: center; font-size: 1.5rem; letter-spacing: 0.5rem;">
                </div>
                
                <button type="submit" class="button primary full-width">Verify & Activate</button>
            </form>
            
            <div class="actions">
                <a href="register.php" class="button secondary full-width">Back to Register</a>
            </div>
        </div>
    </div>
</body>
</html>
