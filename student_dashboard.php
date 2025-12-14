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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<style>
        /* Keep the rest of your styles and add/update these */
        
        /* HEADER & ICON STYLES */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 1;
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px; 
        }

        /* Container for the dropdown */
        .notification-container {
            position: relative; /* Essential for positioning the dropdown content */
        }

        /* Icon Button Styles */
        .notification-icon-button {
            position: relative;
            font-size: 1.5em; 
            color: #333; /* Always visible color */
            text-decoration: none;
            padding: 5px;
            transition: color 0.2s;
        }
        .notification-icon-button:hover {
            color: #007bff;
        }

        /* Red Badge Styles */
        .notification-badge {
            position: absolute;
            top: 0px; 
            right: -5px; 
            background-color: #dc3545; 
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            font-size: 0.55em; 
            font-weight: bold;
            line-height: 1;
            min-width: 18px;
            text-align: center;
            box-shadow: 0 0 0 2px white;
        }

        /* Dropdown Styling */
        .notification-dropdown-content {
            display: none; /* CRUCIAL: Hidden by default */
            position: absolute;
            right: 0;
            top: 40px; 
            background-color: #f9f9f9;
            min-width: 300px;
            max-height: 400px;
            overflow-y: auto;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 10;
            border-radius: 5px;
            border: 1px solid #ddd;
            padding: 10px;
        }

        .notification-dropdown-content h3 {
            margin-top: 0;
            font-size: 1.1em;
            padding-bottom: 5px;
            border-bottom: 1px solid #eee;
            color: #333;
        }

        .notification-dropdown-content .notification-item {
            border-bottom: 1px dashed #e9e9e9;
            padding: 10px 0;
            font-size: 0.9em;
        }
        .notification-dropdown-content .notification-item:last-child {
            border-bottom: none;
        }
        .notification-dropdown-content .no-announcements {
            padding: 15px 0;
            text-align: center;
            color: #6c757d;
        }

        /* Mark Read Button Style */
        .mark-read-area {
            text-align: center;
            padding-top: 10px;
            border-top: 1px solid #eee;
        }
        .mark-all-read-btn {
            display: inline-block;
            color: #007bff;
            text-decoration: none;
            font-size: 0.9em;
            padding: 5px 10px;
            border-radius: 3px;
            transition: background-color 0.2s;
        }
        .mark-all-read-btn:hover {
            background-color: #e9ecef;
        }

        /* Class toggled by JavaScript */
        .show {
    display: block !important; /* Use !important temporarily if necessary to force override */
        }

        
        /*NEW QUIZ CARD STYLES*/
        .quiz-card-list {
            display: flex;
            flex-direction: column;
            gap: 15px; /* Spacing between cards */
            padding: 0;
        }
        .quiz-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px;
            border-radius: 8px;
            text-decoration: none; /* Remove underline from the <a> tag */
            color: #333;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s;
            cursor: pointer;
            background-color: #f7f7f7; 
            border-left: 5px solid #ccc; /* Generic border for effect */
        }
        .quiz-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            opacity: 0.95;
        }
        .quiz-card .quiz-info {
            display: flex;
            align-items: center;
            flex-grow: 1;
        }
        .quiz-card .quiz-icon {
            font-size: 1.5em;
            margin-right: 15px;
            color: inherit; 
        }
        .quiz-card strong {
            display: block;
            font-size: 1.1em;
            margin-bottom: 2px;
        }
        .quiz-card p {
            margin: 0;
            font-size: 0.9em;
            color: #666;
        }
        .quiz-card .start-button {
            /* This makes the 'Start Quiz' text inside the link look like a proper button */
            padding: 8px 15px;
            border-radius: 4px;
            margin-left: 15px;
            white-space: nowrap;
        }
        
        /* Subject Color Overrides (based on existing classes) */
        .quiz-science { border-left-color: #4CAF50; background-color: #f1f8e9; } /* Green */
        .quiz-art { border-left-color: #FF9800; background-color: #fff3e0; } /* Orange */
        .quiz-history { border-left-color: #2196F3; background-color: #e3f2fd; } /* Blue */
</style>
<body>
    <div class="header">
    <h2>Welcome, <?php echo e($_SESSION['name']); ?>!</h2>
    <div class="header-actions">
        <div class="notification-container">
            <a href="#" id="notification-bell" class="notification-icon-button" title="Announcements">
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
        <a href="logout.php" class="button danger">Logout</a>
    </div>
</div>

    <?php if ($message): ?><div class="msg error"><?php echo e($message); ?></div><?php endif; ?>
    
    <?php if ($display_quote_and_results): ?>
    <div class="container wide" style="margin-top: 20px;">
        <div class="score-card status-passed">
            
            <h2>Results for "<?php echo e($last_quiz_title); ?>"</h2>
            
            <p class="score-label">Your Score:</p>
            <div class="score-display"><?php echo e($last_quiz_score); ?> / <?php echo e($last_quiz_total); ?></div> 
            
            <div class="result-status" style="background-color: #e0f2f1; color: #004d40; border: 1px solid #b2dfdb; font-style: italic; font-size: 1.2em;">
                "<?php echo e($random_quote); ?>"
            </div>
            
            <?php if ($award_stars): ?>
            <div class="congratulations-stars" style="text-align: center;">
                <h3>Congratulations! You excelled!</h3>
                <div class="star-rating" style="display: flex; justify-content: center; gap: 15px; font-size: 3em; color: gold; margin: 15px 0;">
                    <span class="star">★</span>
                    <span class="star">★</span>
                    <span class="star">★</span>
                </div>
            </div>
            <?php endif; ?>
            <div class="summary-details">
                <a href="student_dashboard.php" class="button primary">Back to Dashboard</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="container full-width"> 
        <div class="main-content-card">
            
            <div class="dashboard-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                <div class="card quizzes-section">
                    <h3>Available Public Quizzes</h3>
                    
                    <div style="margin-bottom: 20px; padding: 15px; background-color: #f0f8ff; border: 1px solid #a8c8e1; border-radius: 5px;">
                        <form method="post" class="inline-form" style="display: flex; gap: 10px; align-items: center;">
                            <input type="hidden" name="action" value="join_quiz">
                            <input type="text" name="join_code" placeholder="Enter Private Quiz Code" required style="flex-grow: 1; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                            <button type="submit" class="button success">Join Private Quiz</button>
                        </form>
                    </div>

                    <?php if (empty($quizzes)): ?>
                        <p>No active public quizzes available right now. Ask your admin for a private quiz code if necessary.</p>
                    <?php else: ?>
                        <ul class="quiz-list-colorful">
                            <?php foreach ($quizzes as $quiz): ?>
                                <li class="<?php echo get_quiz_subject_class($quiz['title']); ?>">
                                    <div class="quiz-info">
                                        
                                        <?php 
                                            
                                            $subject_class = get_quiz_subject_class($quiz['title']);
                                            $icon = '';
                                            
                                            
                                            if (strpos($subject_class, 'science') !== false) {
                                                $icon = '<i class="fas fa-flask"></i>';
                                            } elseif (strpos($subject_class, 'math') !== false) {
                                                $icon = '<i class="fas fa-calculator"></i>';
                                            } elseif (strpos($subject_class, 'art') !== false) {
                                                $icon = '<i class="fas fa-palette"></i>';
                                            } elseif (strpos($subject_class, 'history') !==false) {
                                                $icon = '<i class="fas fa-book-open"></i>'; 
                                            } else {
                                                $icon = '<i class="fas fa-question-circle"></i>';
                                            }
                                        ?>
                                        <span class="quiz-icon"><?php echo $icon; ?></span>
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
                
                
                
                <div class="card printables-section" style="width: 400px;"> 
                    <h3>📄 Study Printables</h3>
                    <p style="margin-bottom: 15px; color: #555;">Review material for quizzes you have completed!</p>
                    
                    <?php if (empty($completed_quizzes)): ?>
                        <p style="padding: 10px; background-color: #ffebee; border: 1px solid #ffcdd2; border-radius: 5px; color: #c62828;">
                            You haven't completed any quizzes yet. Finish a quiz to unlock study materials!
                        </p>
                    <?php else: ?>
                        <ul class="printable-list" style="list-style: none; padding: 0;">
                            <?php foreach ($completed_quizzes as $quiz): 
                                $quiz_title_safe = htmlspecialchars($quiz['title']);
                            ?>
                                <li style="display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px dashed #eee;">
                                    <span><i class="fas fa-file-alt" style="color: #00897b; margin-right: 8px;"></i> <?php echo e($quiz_title_safe); ?> - Study Guide</span>
                                    
                                    <a href="generate_printables.php?quiz_id=<?php echo e($quiz['id']); ?>" class="button success small" target="_blank" title="Opens new window. Use your browser's 'Print to PDF' feature to save."><i class="fas fa-download"></i> Download (PDF)</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    
                    <div style="margin-top: 20px; padding: 10px; background-color: #fff3e0; border: 1px solid #ffcc80; border-radius: 5px; font-size: 0.9em; color: #e65100;">
                        *Note: These files contain the questions and **correct answers** from the completed quiz.
                    </div>
                </div>
                
            
                <div class="card ranking-section">
                    <h3>🏆 Student Ranking (Top 10)</h3>
                    <?php if (empty($ranking)): ?>
                        <p>No completed quizzes yet to display a ranking.</p>
                    <?php else: ?>
                        <ul class="ranking-list">
                        <?php $rank = 1; ?> 
                        <?php foreach ($ranking as $entry): ?>
                            <li>
                                <div class="rank-number-container">
                                    <span class="rank-number"><?php echo $rank; ?></span>
                                </div>
                                
                                <span class="rank-user"><?php echo e($entry['name']); ?></span>
                                
                                <div class="rank-score-time">
                                    <?php echo e($entry['score']); ?> Pts (<span class="subject-tag"><?php echo e($entry['quiz_title']); ?></span>)
                                    <span class="time-stamp">Time: <?php echo format_duration($entry['duration_seconds']); ?></span>
                                </div>
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
</body>
</html>
