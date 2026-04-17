<?php
require_once 'config.php';

if (is_logged_in()) {
    redirect(get_user_role() === 'admin' ? 'admin_dashboard.php' : 'student_dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("
        SELECT 
            id, 
            name, 
            password_hash, 
            role  
        FROM 
            users 
        WHERE 
            email = ?
    ");
    
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($pass, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role']; //fetches the ENUM value ('admin' or 'student')
        
        if ($user['role'] === 'admin') {
            redirect('admin_dashboard.php');
        } else {
            redirect('student_dashboard.php');
        }
    } else {
        $error = 'Invalid credentials. Please check your email and password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Quiz System</title>
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
            <h1>🔑 Quiz System Login</h1>
            <p>Welcome back! Please sign in to your account.</p>

            <?php if ($error): ?><div class="msg err"><?php echo e($error); ?></div><?php endif; ?>

            <form method="post">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
<div class="password-field" style="position: relative;">
                        <input type="password" id="password" name="password" required style="padding-right: 40px;">
                        <button type="button" class="password-toggle" onclick="togglePassword('password')" tabindex="-1">👁</button>
                    </div>
                </div>

                <button type="submit" class="button primary full-width">Log In</button>
            </form>
            <div style="text-align: center; margin-top: 1rem;">
                <a href="register.php" class="button secondary">Don't have an account? Register</a>
            </div>
        </div>
    </div>
</body>
</html>
