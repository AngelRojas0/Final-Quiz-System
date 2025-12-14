<?php
session_start();

// Database Credentials (Update these!)
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); 
define('DB_NAME', 'quiz_data');

// EMAIL CONFIGURATION
define('ADMIN_EMAIL', 'angelnicole331203@gmail.com'); 


// Connect to Database
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Global Functions (Required everywhere)

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function get_user_role() {
    return $_SESSION['role'] ?? 'guest';
}

function redirect($page) {
    header("Location: $page");
    exit();
}

function require_role($required_role) {
    if (!is_logged_in() || get_user_role() !== $required_role) {
        redirect('index.php');
    }
}

function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Helper function to format seconds into minutes:seconds
function format_duration($seconds) {
    $minutes = floor($seconds / 60);
    $remaining_seconds = $seconds % 60;
    return sprintf("%d:%02d min", $minutes, $remaining_seconds);
}

// Helper function for colored quizzes based on title
function get_quiz_subject_class($quiz_title) {
    $title_lower = strtolower($quiz_title);
    if (strpos($title_lower, 'science') !== false) {
        return 'quiz-subject-science';
    } elseif (strpos($title_lower, 'math') !== false) {
        return 'quiz-subject-math';
    } elseif (strpos($title_lower, 'art') !== false) { 
        return 'quiz-subject-art';
    } elseif (strpos($title_lower, 'history') !== false) { 
        return 'quiz-subject-history';
    }
    return 'quiz-subject-default'; 
}
// Note: If you need to use the set_notification function in quiz.php, 
// you should also define it here in config.php since it's a global utility function.
/*
if (!function_exists('set_notification')) {
    function set_notification($message, $type = 'success') {
        $_SESSION['flash_message'] = ['text' => $message, 'type' => $type];
    }
}
*/
?>