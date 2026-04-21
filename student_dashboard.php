<?php
require_once 'config.php';
require_role('student');

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$message = '';

// 1. MARK NOTIFICATIONS AS READ LOGIC (Action Handler)

if (isset($_GET['action']) && $_GET['action'] === 'mark_read' && isset($_GET['ids']) && !empty($_GET['ids'])) {
    // Sanitize the IDs string: ensures it only contains numbers and commas
    $ids_string = preg_replace('/[^0-9,]/', '', $_GET['ids']);

    if (!empty($ids_string) && isset($_SESSION['user_id'])) {
        try {
            $user_id_int = (int)$_SESSION['user_id'];
            
            // This is the core update query
            $sql = "UPDATE notifications 
                    SET is_read = 1 
                    WHERE id IN ({$ids_string}) 
                    AND (target_user_id = :user_id OR target_role = 'student' OR target_role = 'all')";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':user_id', $user_id_int, PDO::PARAM_INT);
            
            $stmt->execute();
            
        } catch (PDOException $e) {
            // Log error
            // error_log("Notification Mark Read DB Error: " . $e->getMessage()); 
        }
    }
    
    // Redirect back to clean URL
    header("Location: student_dashboard.php");
    exit();
}


// Handle Join Code Submission 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'join_quiz') {
    $join_code = trim(strtoupper($_POST['join_code'] ?? ''));
    
    if (empty($join_code)) {
        $message = "⚠️ Please enter a valid join code.";
    } else {
        // Find the quiz by join code
        $quiz_stmt = $pdo->prepare("SELECT id FROM quizzes WHERE join_code = ? AND is_active = 1");
        $quiz_stmt->execute([$join_code]);
        $quiz = $quiz_stmt->fetch();
        
        if ($quiz) {
            redirect('quiz.php?quiz_id=' . $quiz['id']);
        } else {
            $message = "⚠️ Invalid or inactive join code.";
        }
    }
}
// (Code ends after handling join quiz POST request)


// 2. FETCH UNREAD NOTIFICATIONS LOGIC

$notifications = []; 
try {
    // CRITICAL: This query must match the WHERE clause logic of the mark_read action
    $notif_stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE (target_role = 'student' OR target_user_id = ? OR target_role = 'all') 
        AND is_read = 0 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    // Execute using the user ID defined near the top
    $notif_stmt->execute([$user_id]);
    $notifications = $notif_stmt->fetchAll();
    
} catch (PDOException $e) {
    // Handle database error if necessary
}

$notification_count = count($notifications);



// CRITICAL FIX: Only show ACTIVE AND PUBLIC quizzes (where join_code is NULL)
$quizzes = $pdo->query("SELECT * FROM quizzes WHERE is_active = 1 AND join_code IS NULL")->fetchAll();

// Ranking Logic (Top 10)
// This query uses the duration_seconds calculated from the fixed database schema
$ranking = []; 
try {
    $ranking_stmt = $pdo->query("
        SELECT 
            u.name, 
            q.title AS quiz_title,
            TIMESTAMPDIFF(SECOND, r.start_time, r.end_time) AS duration_seconds,
            r.score
        FROM results r
        JOIN users u ON r.user_id = u.id
        JOIN quizzes q ON r.quiz_id = q.id
        WHERE r.is_finished = 1 AND u.role = 'student'
        ORDER BY r.score DESC, duration_seconds ASC 
        LIMIT 10
    ");
    $ranking = $ranking_stmt->fetchAll(); 
} catch (PDOException $e) {
    // Error handling is less critical now that the schema is fixed
}


//Printables Logic: Find quizzes student has finished 
$completed_quizzes = [];
try {
    $completed_quizzes_stmt = $pdo->prepare("
        SELECT DISTINCT q.id, q.title
        FROM results r
        JOIN quizzes q ON r.quiz_id = q.id
        WHERE r.user_id = ? AND r.is_finished = 1
        ORDER BY q.title ASC
    ");
    $completed_quizzes_stmt->execute([$user_id]);
    $completed_quizzes = $completed_quizzes_stmt->fetchAll();
} catch (PDOException $e) {
   
}


// Quotes for top students
$quotes = [
    "A journey of a thousand miles begins with a single step.",
    "The mind is everything. What you think you become.",
    "Believe you can and you're halfway there.",
    "The best way to predict the future is to create it. <3",
    "Nothing is impossible if hindi ka tamad mag study!",
    "WOW BERRY GOOD NAG STUDY ANG FERSON :)",
    "SANA IPAGPATULOY MO ANG IYONG PAGIGING MASIPAG NA BATA INENG OR INONG BASTA IKAW TINUTUKOY KO"         
];

// START PHP LOGIC FOR POST-QUIZ DISPLAY

$display_quote_and_results = false;
$random_quote = '';

if (isset($_SESSION['quiz_submitted']) && $_SESSION['quiz_submitted']) {
    // 1. Retrieve data stored in session by quiz.php
    $last_quiz_score = $_SESSION['last_quiz_score'] ?? 0;
    $last_quiz_total = $_SESSION['last_quiz_total'] ?? 0;
    $last_quiz_title = $_SESSION['last_quiz_title'] ?? 'Quiz';
    
    // 2. AWARD LOGIC: Determine if stars are awarded (80% threshold)
    $passing_threshold = 0.8; // 80% score or better
    $score_percentage = ($last_quiz_total > 0) ? ($last_quiz_score / $last_quiz_total) : 0;
    $award_stars = $score_percentage >= $passing_threshold;
    
    // 3. Pick a random quote
    $random_key = array_rand($quotes);
    $random_quote = $quotes[$random_key];
    
    $display_quote_and_results = true;
    
    // 4. Clear session flags to prevent repeated display on refresh
    unset($_SESSION['quiz_submitted']);
    unset($_SESSION['last_quiz_score']);
    unset($_SESSION['last_quiz_total']);
    unset($_SESSION['last_quiz_title']);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Dashboard - Quiz System</title>
<link rel="stylesheet" href="styles.css">
<link rel="stylesheet" href="modal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="student">
<div class="header">
    <div class="header-left">
        <h2>Welcome, <?php echo e($_SESSION['name']); ?>!</h2>
    </div>
    <div class="header-actions">
        <div class="notification-container">
            <a href="#" id="notification-bell" class="notification-button" title="Announcements">
                <i class="fas fa-bell"></i>
                <?php if ($notification_count > 0): ?>
                    <span class="notification-badge"><?php echo $notification_count; ?></span>
                <?php endif; ?>
            </a>

            <div id="notification-dropdown" class="notification-dropdown-content">
                <?php if (!empty($notifications)): ?>
                    <h3>🚨 New Announcements (<?php echo $notification_count; ?>)</h3>
                    <?php foreach ($notifications as $notification): ?>
                        <div class="notification-item">
                            <strong>[<?php echo e(date('M d', strtotime($notification['created_at']))); ?>]</strong> 
                            <?php echo e($notification['content']); ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="mark-read-area">
<a href="?action=mark_read&ids=<?php echo implode(',', array_column($notifications, 'id')); ?>" class="mark-all-read-btn">Mark All As Read</a>
</div>
                <?php else: ?>
                    <div class="notification-item no-announcements">No new announcements.</div>
                <?php endif; ?>
            </div>
        </div>
        <a href="#" class="button danger logout-trigger" onclick="showLogoutModal(); return false;">Logout</a>
    </div>
</div>

    <?php if ($message): ?><div class="msg err"><?php echo e($message); ?></div><?php endif; ?>
    
    <?php if ($display_quote_and_results): ?>
    <div class="container wide">
        <div class="card">
            <h2>🎉 Quiz Results: "<?php echo e($last_quiz_title); ?>"</h2>
            <div style="font-size: 3rem; color: var(--primary); text-align: center; margin: 1rem 0;">
                <?php echo e($last_quiz_score); ?> / <?php echo e($last_quiz_total); ?>
            </div>
            <div class="msg success" style="font-style: italic; font-size: 1.1rem;">
                "<?php echo e($random_quote); ?>"
            </div>
            <?php if ($award_stars): ?>
            <div style="text-align: center; margin: 2rem 0;">
                <h3>⭐ Excellent Work! ⭐</h3>
                <div style="font-size: 4rem; color: gold;">★ ★ ★</div>
            </div>
            <?php endif; ?>
            <div class="actions">
                <a href="student_dashboard.php" class="button primary">Back to Dashboard</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
<div class="container wide">
        <div class="main-content-card">
            <div class="dashboard-grid">
                <div class="card">
                    <h3>📚 Available Quizzes</h3>
                    
                    <div class="card" style="background: #eff6ff; border-color: #93c5fd;">
                        <form method="post" style="display: flex; gap: 1rem; align-items: center;">
                            <input type="hidden" name="action" value="join_quiz">
                            <input type="text" name="join_code" placeholder="Enter Private Quiz Code" style="flex: 1;">
                            <button type="submit" class="button success">Join Quiz</button>
                        </form>
                    </div>

                    <?php if (empty($quizzes)): ?>
                        <p class="msg">No public quizzes available. Ask for a private code!</p>
                    <?php else: ?>
                        <ul class="quiz-list-colorful">
                            <?php foreach ($quizzes as $quiz): ?>
                                <li class="<?php echo get_quiz_subject_class($quiz['title']); ?>">
                                    <div class="quiz-info">
                                        <span class="quiz-icon">
                                            <?php 
                                            $subject_class = get_quiz_subject_class($quiz['title']);
                                            $icon_map = [
                                                'science' => 'fas fa-flask',
                                                'math' => 'fas fa-calculator', 
                                                'art' => 'fas fa-palette',
                                                'history' => 'fas fa-book-open'
                                            ];
                                            $icon = $icon_map[strpos($subject_class, key($icon_map)) !== false ? key($icon_map) : 'question-circle'] ?? 'fas fa-question-circle';
                                            echo "<i class='$icon'></i>";
                                            ?>
                                        </span>
                                        <div class="quiz-details">
                                            <strong><?php echo e($quiz['title']); ?></strong>
                                            <p><?php echo e($quiz['description']); ?></p>
                                        </div>
                                    </div>
                                    <a href="quiz.php?quiz_id=<?php echo e($quiz['id']); ?>" class="button primary">Start Quiz</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                
                <div class="card">
                    <h3>📄 Study Printables</h3>
                    <p style="color: var(--text-2); margin-bottom: 1rem;">Review completed quizzes</p>
                    
                    <?php if (empty($completed_quizzes)): ?>
                        <div class="msg">Complete a quiz to unlock study materials!</div>
                    <?php else: ?>
                        <ul style="list-style: none; padding: 0;">
                            <?php foreach ($completed_quizzes as $quiz): ?>
                                <li style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border);">
                                    <span><i class="fas fa-file-pdf" style="color: var(--primary); margin-right: 0.5rem;"></i><?php echo e($quiz['title']); ?></span>
                                    <a href="generate_printables.php?quiz_id=<?php echo e($quiz['id']); ?>" class="button success small" target="_blank">
                                        <i class="fas fa-download"></i> PDF
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="msg" style="margin-top: 1rem; font-size: 0.875rem;">
                            * Contains questions + answers for study
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="card">
                    <h3>🏆 Leaderboard</h3>
                    <?php if (empty($ranking)): ?>
                        <p>No results yet.</p>
                    <?php else: ?>
                        <ul class="ranking-list" style="list-style: none; padding: 0;">
                            <?php $rank = 1; foreach ($ranking as $entry): ?>
                                <li style="display: flex; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border);">
                                    <div style="width: 2rem; font-weight: 700; font-size: 1.1rem; color: <?php echo $rank <= 3 ? '#facc15' : 'var(--text-2)'; ?>">
                                        <?php echo $rank; ?>
                                    </div>
                                    <span style="flex: 1; font-weight: 500;"><?php echo e($entry['name']); ?></span>
                                    <span style="font-weight: 600; color: var(--primary);"><?php echo e($entry['score']); ?> pts</span>
                                    <small style="color: var(--text-2);"><?php echo format_duration($entry['duration_seconds']); ?></small>
                                </li>
                            <?php $rank++; endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script>
    // 1. Get the bell icon and the dropdown by their specific IDs
    const bellIcon = document.getElementById('notification-bell');
    const dropdown = document.getElementById('notification-dropdown');

    // 2. Check if both elements exist before adding the listener
    if (bellIcon && dropdown) {
        // Toggle the 'show' class when the bell icon is clicked
        bellIcon.addEventListener('click', function(event) {
            event.preventDefault(); // Stop the link from navigating/reloading
            dropdown.classList.toggle('show');
        });
    }

    // 3. Close the dropdown if the user clicks outside of it
    window.addEventListener('click', function(event) {
        // Check if the dropdown exists, is currently open, and the click target is NOT inside the .notification-container
        if (dropdown && dropdown.classList.contains('show') && !event.target.closest('.notification-container')) {
            dropdown.classList.remove('show');
        }
    });
</script>

<?php include 'components/logout_modal.php'; ?>

<script>
function showLogoutModal() {
  const modal = document.getElementById('logoutModal');
  if (modal) modal.classList.add('show');
}
function closeLogoutModal() {
  const modal = document.getElementById('logoutModal');
  if (modal) modal.classList.remove('show');
}
document.addEventListener('click', e => {
  const modal = document.getElementById('logoutModal');
  if (e.target.id === 'logoutModal' && modal) closeLogoutModal();
});
const logoutBtn = document.querySelector('.logout-trigger');
if (logoutBtn) logoutBtn.addEventListener('click', e => {
  e.preventDefault();
  showLogoutModal();
});
</script>
</body>
</html>

