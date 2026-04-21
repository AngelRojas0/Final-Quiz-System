<?php
require_once 'config.php';
echo "<h2>DB Test</h2>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $users = $stmt->fetch()['count'];
    echo "✅ Connected! Users: $users<br>";
    
    $stmt = $pdo->query("SELECT title FROM quizzes LIMIT 1");
    $quiz = $stmt->fetch();
    echo "Quizzes: " . ($quiz ? $quiz['title'] : 'None') . "<br>";
    
echo "<a href='index.php'>Go to App</a>";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>

