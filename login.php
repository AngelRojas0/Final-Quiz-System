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
</head>
<body class="center-page">
    <div class="container">
        <h1>🔑 Login</h1>

        <?php if ($error): ?><div class="msg err"><?php echo e($error); ?></div><?php endif; ?>

        <form method="post">
            <label>Email <input type="email" name="email" required></label>
            <label>Password <input type="password" name="password" required></label>

            <div class="actions">
                <button type="submit" class="button primary">Log In</button>
                <a href="register.php" class="link-secondary">Register here</a>
            </div>
        </form>
    </div>
</body>
</html>