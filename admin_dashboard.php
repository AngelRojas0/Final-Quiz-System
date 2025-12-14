<?php
require_once 'config.php';
// IMPORTANT: Ensure `session_start()` is called, either in `config.php` or at the very start of this file.
// If it's not in config.php, you must add it here:
// if (session_status() == PHP_SESSION_NONE) {
//     session_start();
// }
require_role('admin');

$user_id = $_SESSION['user_id'];

// --- Notification Functions (Flash Message Pattern) ---

/**
 * Stores a one-time message in the session to be displayed after the next redirect.
 * @param string $message The notification text.
 * @param string $type The type of notification (e.g., 'success', 'warning', 'danger').
 */
if (!function_exists('set_notification')) {
    function set_notification($message, $type = 'success') {
        $_SESSION['flash_message'] = ['text' => $message, 'type' => $type];
    }
}

// --- Helper Functions (Ensures 'e' and 'format_duration' are available) ---

// HTML escaping function
if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

// Duration formatting function (used in Monitoring and KPI sections)
if (!function_exists('format_duration')) {
    function format_duration($seconds) {
        if ($seconds === null || $seconds === 0) return '0s';
        $seconds = (int)$seconds;
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        $time_parts = [];
        if ($h > 0) $time_parts[] = $h . 'h';
        if ($m > 0) $time_parts[] = $m . 'm';
        if ($s > 0 || empty($time_parts)) $time_parts[] = $s . 's';
        return implode(' ', $time_parts);
    }
}

// =================================================================
// MARK NOTIFICATIONS AS READ LOGIC (Action Handler)
// =================================================================
if (isset($_GET['action']) && $_GET['action'] === 'mark_read' && isset($_GET['ids']) && !empty($_GET['ids'])) {
    // 1. Sanitize the IDs string: ensure it only contains numbers and commas
    $ids_string = preg_replace('/[^0-9,]/', '', $_GET['ids']);

    if (!empty($ids_string) && isset($_SESSION['user_id'])) {
        try {
            $user_id_int = (int)$_SESSION['user_id'];
            
            // Query modified to target the ADMIN role
            $sql = "UPDATE notifications 
                    SET is_read = 1 
                    WHERE id IN ({$ids_string}) 
                    AND (target_user_id = :user_id OR target_role = 'admin' OR target_role = 'all')";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':user_id', $user_id_int, PDO::PARAM_INT);
            
            $stmt->execute();
            
        } catch (PDOException $e) {
            // error_log("Notification Mark Read DB Error: " . $e->getMessage()); 
        }
    }
    
    // Redirect back to clean URL immediately to refresh the dashboard and clear the badge
    header("Location: admin_dashboard.php");
    exit();
}

// --- Notification Retrieval (Check for flash message) ---
$message = '';
$message_type = 'success'; // Default type

if (isset($_SESSION['flash_message'])) {
    $flash = $_SESSION['flash_message'];
    $message = $flash['text'];
    $message_type = $flash['type'];
    unset($_SESSION['flash_message']); // Clear the message after retrieval
}
// --- End Notification Retrieval ---


// =================================================================
// Fetch Admin Notifications 
// =================================================================
$notifications = []; // Initialize the array
try {
    $notif_stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE (target_role = 'admin' OR target_user_id = ?) 
        AND is_read = 0 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $notif_stmt->execute([$user_id]);
    $notifications = $notif_stmt->fetchAll();
    
} catch (PDOException $e) {
    // Handle database error if necessary
}

$notification_count = count($notifications);

// --- END NEW PHP LOGIC ---


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'create_quiz') {
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        
        $is_private = isset($_POST['is_private']);
        // Generate an 8-character alphanumeric code if private, otherwise NULL
        $join_code = $is_private ? substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 8) : NULL;
        
        if (!empty($title)) {
            
            // 1. Insert the new quiz
            $stmt = $pdo->prepare("INSERT INTO quizzes (title, description, created_by, join_code) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $desc, $_SESSION['user_id'], $join_code]);
            
            // =========================================================
            // ✅ NEW: CREATE NOTIFICATION FOR STUDENTS 
            // =========================================================
            
            // Construct the notification message
            $notification_content = "📢 New Quiz Available: " . $title;
            if ($join_code) {
                $notification_content .= " (Private, Code: " . $join_code . ")";
            }
            
            // Insert the notification, targeting the 'student' role
            $notif_stmt = $pdo->prepare("INSERT INTO notifications (content, target_role) VALUES (?, 'student')");
            $notif_stmt->execute([$notification_content]);
            
            // =========================================================
            // END NEW CODE
            // =========================================================

            $msg_suffix = $join_code ? " It is a <strong>Private Quiz</strong> with code: <strong>{$join_code}</strong>." : " It is a Public Quiz.";
            
            set_notification("Quiz '{$title}' created successfully!" . $msg_suffix, 'success');
            header('Location: admin_dashboard.php');
            exit;
        } else {
            set_notification("⚠️ Quiz creation failed: Title cannot be empty.", 'warning');
            header('Location: admin_dashboard.php');
            exit;
        }
    } 
    
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete_all_results') {
        
        $delete_stmt = $pdo->prepare("
            DELETE results 
            FROM results
            INNER JOIN users ON results.user_id = users.id
            WHERE users.role = 'student'
        ");
        $delete_stmt->execute();
        $count = $delete_stmt->rowCount();
        
        set_notification("✅ Successfully deleted <strong>{$count}</strong> student quiz attempt records!", 'success');
        header('Location: admin_dashboard.php');
        exit;
    } 

    elseif (isset($_POST['action']) && $_POST['action'] === 'delete_result_single') {
        $result_id_to_delete = (int)$_POST['result_id'];
        
        $delete_stmt = $pdo->prepare("
            DELETE FROM results 
            WHERE id = ? 
            AND user_id IN (SELECT id FROM users WHERE role = 'student')
        ");
        $delete_stmt->execute([$result_id_to_delete]);
        
        if ($delete_stmt->rowCount()) {
            set_notification("🗑️ Successfully deleted quiz record #{$result_id_to_delete}.", 'success');
        } else {
            set_notification("⚠️ Could not delete the specified quiz record or it did not belong to a student.", 'warning');
        }
        header('Location: admin_dashboard.php');
        exit;
    }
    
    elseif (isset($_POST['action']) && isset($_POST['quiz_id'])) {
        $quiz_id = (int)$_POST['quiz_id'];
        $status = $_POST['action'] === 'activate' ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE quizzes SET is_active = ? WHERE id = ?");
        $stmt->execute([$status, $quiz_id]);
        
        $action_msg = $status ? 'Activated' : 'Deactivated';
        
        set_notification("Quiz #{$quiz_id} status updated to: <strong>{$action_msg}</strong>.", 'success');
        header('Location: admin_dashboard.php');
        exit;
    }
}

// Fetch all quizzes (includes the join_code column now)
$all_quizzes = $pdo->query("SELECT * FROM quizzes ORDER BY id DESC")->fetchAll();

// Fetch student monitoring data
$monitoring_stmt = $pdo->query("
    SELECT 
        r.id AS result_id, 
        u.name AS student_name, 
        q.title AS quiz_title, 
        r.start_time, 
        r.end_time, 
        r.score, 
        r.is_finished,
        TIMESTAMPDIFF(SECOND, r.start_time, r.end_time) AS duration_seconds
    FROM results r
    JOIN users u ON r.user_id = u.id
    JOIN quizzes q ON r.quiz_id = q.id
    WHERE u.role = 'student'
    ORDER BY r.is_finished ASC, r.start_time DESC
");
$monitoring_data = $monitoring_stmt->fetchAll();

// --- KPI Calculation Function (The API) ---
/**
 * @param array 
 * @param array 
 * @return array 
 */
function calculate_kpis($all_quizzes, $monitoring_data) {
    $total_quizzes = count($all_quizzes);
    $total_results = count($monitoring_data);
    
    // Filter for only finished attempts for score/duration calculations
    $finished_attempts = array_filter($monitoring_data, fn($d) => (bool)$d['is_finished']);
    
    $total_score = 0;
    $total_duration = 0;
    $finished_count = count($finished_attempts);
    
    foreach ($finished_attempts as $attempt) {
        // Accumulate score and duration
        $total_score += (float)($attempt['score'] ?? 0); 
        $total_duration += (int)($attempt['duration_seconds'] ?? 0);
    }
    
    // Calculate Averages
    $avg_score = $finished_count > 0 ? round($total_score / $finished_count, 1) : 'N/A';
    $avg_duration = $finished_count > 0 ? format_duration(round($total_duration / $finished_count)) : 'N/A';
    
    return [
        'total_quizzes' => $total_quizzes,
        'total_attempts' => $total_results,
        'finished_attempts' => $finished_count,
        'avg_score' => $avg_score,
        'avg_duration' => $avg_duration,
        'in_progress_attempts' => $total_results - $finished_count
    ];
}

// --- KPI Report Generation ---
$kpis = calculate_kpis($all_quizzes, $monitoring_data);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Quiz System</title>
    <link rel="stylesheet" href="styles.css"> 
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        /* Minimal styles for KPI report to look good, assuming styles.css is basic */
        .admin-view {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .kpi-report-card {
            background-color: #f8f9fa;
            border-left: 5px solid #007bff;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .kpi-item {
            padding: 15px;
            border-radius: 8px;
            background-color: #ffffff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .kpi-value {
            font-size: 2em;
            font-weight: 700;
            color: #007bff;
            line-height: 1;
        }
        .kpi-label {
            font-size: 0.9em;
            color: #6c757d;
            margin-top: 5px;
        }
        .kpi-success { color: #28a745; }
        .kpi-warning { color: #ffc107; }
        .kpi-danger { color: #dc3545; }
        
        /* Styles for Status tags */
        .status-active, .status-inactive, .status-finished, .status-progress {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.8em;
            font-weight: bold;
            color: white;
            white-space: nowrap;
        }
        .status-active { background-color: #28a745; }
        .status-inactive { background-color: #dc3545; }
        .status-finished { background-color: #007bff; }
        .status-progress { background-color: #ffc107; }
        
        .inline-form {
            display: inline-block;
        }

        /* --- STYLES FOR NOTIFICATION FUNCTION --- */
        .msg {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-weight: bold;
        }
        .msg.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .msg.warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        .msg.danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        /* --- END NOTIFICATION STYLES --- */
        
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

       
        .notification-container {
            position: relative; 
        }

        /* Icon Button Styles */
        .notification-icon-button {
            position: relative;
            font-size: 1.5em; 
            color: #333; 
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

        .notification-dropdown-content {
            display: none;
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

        
        .show {
            display: block !important; 
        }
    </style>

    <script>
        function confirmDeleteAllResults() {
            return confirm("WARNING! Are you absolutely sure you want to delete ALL student quiz attempt records? This action cannot be undone.");
        }
        
        function confirmDeleteSingleResult(id) {
            return confirm("Are you sure you want to delete this single result (ID: " + id + ")?");
        }
    </script>
</head>
<body>
    <div class="header">
        <h2>Admin Panel, Welcome <?php echo e($_SESSION['name']); ?></h2>
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

    <div class="container wide admin-view">
        
        <?php if ($message): ?><div class="msg <?php echo e($message_type); ?>"><?php echo $message; ?></div><?php endif; ?>

        <div class="card kpi-report-card">
            <h3>📈 Key Performance Indicators (KPI)</h3>
            <div class="kpi-grid">
                <div class="kpi-item">
                    <span class="kpi-value"><?php echo e($kpis['total_quizzes']); ?></span>
                    <span class="kpi-label">Total Quizzes Created</span>
                </div>
                <div class="kpi-item">
                    <span class="kpi-value"><?php echo e($kpis['total_attempts']); ?></span>
                    <span class="kpi-label">Total Student Attempts</span>
                </div>
                <div class="kpi-item">
                    <span class="kpi-value kpi-success"><?php echo e($kpis['avg_score']); ?></span>
                    <span class="kpi-label">Avg. Score (Finished)</span>
                </div>
                <div class="kpi-item">
                    <span class="kpi-value"><?php echo e($kpis['avg_duration']); ?></span>
                    <span class="kpi-label">Avg. Duration (Finished)</span>
                </div>
                <div class="kpi-item">
                    <span class="kpi-value kpi-warning"><?php echo e($kpis['in_progress_attempts']); ?></span>
                    <span class="kpi-label">Attempts In Progress</span>
                </div>
            </div>
        </div>
        <div class="card quiz-creator-card">
            <h3>📝 Create New Quiz</h3>
            <form method="post" class="create-quiz-form">
                <input type="hidden" name="action" value="create_quiz">
                
                <div class="form-group-inline">
                    <label>Title <input type="text" name="title" required></label>
                    <label>Description <textarea name="description"></textarea></label>
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_private" value="1"> 
                        Make Private (Requires Join Code)
                    </label>
                </div>
                
                <button type="submit" class="button primary">Create Quiz</button>
            </form>
        </div>
        
        <div class="card quiz-management-card">
            <h3>🗂️ Quiz Management</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Type</th> 
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_quizzes)): ?>
                        <tr><td colspan="4" class="text-center">No quizzes have been created yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($all_quizzes as $quiz): ?>
                            <tr>
                                <td><?php echo e($quiz['title']); ?></td>
                                <td>
                                    <?php if ($quiz['join_code']): ?>
                                        <span class="status-inactive" style="background-color: #f7b731;">Private</span>
                                        <br><small>Code: <strong><?php echo e($quiz['join_code']); ?></strong></small> 
                                    <?php else: ?>
                                        <span class="status-active" style="background-color: #3b906a;">Public</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $quiz['is_active'] ? '<span class="status-active">Active</span>' : '<span class="status-inactive">Inactive</span>'; ?></td>
                                <td class="action-cell">
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="quiz_id" value="<?php echo e($quiz['id']); ?>">
                                        <input type="hidden" name="action" value="<?php echo $quiz['is_active'] ? 'deactivate' : 'activate'; ?>">
                                        <button type="submit" class="button small <?php echo $quiz['is_active'] ? 'danger' : 'success'; ?>">
                                            <?php echo $quiz['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                        <a href="edit_quiz.php?quiz_id=<?php echo e($quiz['id']); ?>" class="button small primary">Edit</a>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>


        <div class="card monitoring-card">
            <h3>📊 Student Activity Monitoring</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Quiz</th>
                        <th>Start Time</th>
                        <th>Finish Time</th>
                        <th>Duration</th>
                        <th>Score</th>
                        <th>Status</th>
                        <th>Actions</th> </tr>
                </thead>
                <tbody>
                    <?php if (empty($monitoring_data)): ?>
                        <tr><td colspan="8" class="text-center">No quiz attempts recorded yet.</td></tr> 
                    <?php else: ?>
                        <?php foreach ($monitoring_data as $data): ?>
                            <tr>
                                <td><?php echo e($data['student_name']); ?></td>
                                <td><?php echo e($data['quiz_title']); ?></td>
                                <td><?php echo e(date('H:i:s', strtotime($data['start_time']))); ?></td>
                                <td><?php echo $data['end_time'] ? e(date('H:i:s', strtotime($data['end_time']))) : 'N/A'; ?></td>
                                <td><?php echo $data['is_finished'] ? format_duration($data['duration_seconds']) : '...'; ?></td>
                                <td><?php echo $data['is_finished'] ? e($data['score']) : 'N/A'; ?></td>
                                <td><?php echo $data['is_finished'] ? '<span class="status-finished">Finished</span>' : '<span class="status-progress">In Progress</span>'; ?></td>
                                
                                <td>
                                    <form method="post" onsubmit="return confirmDeleteSingleResult(<?php echo e($data['result_id']); ?>);" class="inline-form">
                                        <input type="hidden" name="action" value="delete_result_single">
                                        <input type="hidden" name="result_id" value="<?php echo e($data['result_id']); ?>">
                                        <button type="submit" class="button small danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div style="margin-top: 20px; text-align: right;">
                <form method="post" onsubmit="return confirmDeleteAllResults();">
                    <input type="hidden" name="action" value="delete_all_results">
                    <button type="submit" class="button danger large-button">
                        ❌ Delete ALL Student Results
                    </button>
                </form>
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