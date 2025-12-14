<?php
require_once 'config.php';

// --- PHPMailer Setup ---
// These lines load the library. Ensure the 'PHPMailer' folder is in your project directory.
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';
// -----------------------

require_role('student');

$quiz_id = (int)($_GET['quiz_id'] ?? 0);
$user_id = $_SESSION['user_id'];
$error = '';
$message = '';

// Check if quiz exists and is active
$quiz = $pdo->prepare("SELECT * FROM quizzes WHERE id = ? AND is_active = 1");
$quiz->execute([$quiz_id]);
$quiz_data = $quiz->fetch();

if (!$quiz_data) {
    redirect('student_dashboard.php');
}

// Check for existing unfinished attempt or start new one
$result_stmt = $pdo->prepare("SELECT * FROM results WHERE user_id = ? AND quiz_id = ? AND is_finished = 0");
$result_stmt->execute([$user_id, $quiz_id]);
$current_result = $result_stmt->fetch();

if (!$current_result) {
    // Start a new attempt
    $pdo->prepare("INSERT INTO results (user_id, quiz_id, start_time) VALUES (?, ?, NOW())")->execute([$user_id, $quiz_id]);
    $current_result = $pdo->lastInsertId();
    $message = "Quiz started!";
}

$questions = $pdo->prepare("
    SELECT q.id AS question_id, q.question_text, 
            a.id AS answer_id, a.answer_text, a.is_correct
    FROM questions q 
    JOIN answers a ON q.id = a.question_id 
    WHERE q.quiz_id = ? 
    ORDER BY q.id, a.id
");
$questions->execute([$quiz_id]);
$quiz_questions = [];

// Group answers by question
while ($row = $questions->fetch()) {
    $q_id = $row['question_id'];
    if (!isset($quiz_questions[$q_id])) {
        $quiz_questions[$q_id] = [
            'text' => $row['question_text'],
            'answers' => []
        ];
    }
    $quiz_questions[$q_id]['answers'][] = [
        'id' => $row['answer_id'],
        'text' => $row['answer_text'],
        'is_correct' => $row['is_correct']
    ];
}

// --- HANDLE FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'finish') {
    $score = 0;
    
    // 1. Grading Logic
    foreach ($quiz_questions as $q_id => $q_data) {
        // Submitted answer is explicitly cast to integer
        $submitted_answer_id = (int)($_POST["q{$q_id}"] ?? 0);
        
        foreach ($q_data['answers'] as $answer) {
            if ($answer['id'] == $submitted_answer_id && $answer['is_correct']) {
                $score++;
                break;
            }
        }
    }

    // Update the result record in the database
    $update_stmt = $pdo->prepare("UPDATE results SET end_time = NOW(), score = ?, is_finished = 1 WHERE user_id = ? AND quiz_id = ? AND is_finished = 0");
    $update_stmt->execute([$score, $user_id, $quiz_id]);

    // =========================================================
    // 📧 SEND EMAIL NOTIFICATIONS (Student & Admin)
    // =========================================================
    
    // 1. Get student details and set admin email
    $user_stmt = $pdo->prepare("SELECT email, name FROM users WHERE id = ?");
    $user_stmt->execute([$user_id]);
    $student = $user_stmt->fetch();

    $admin_email = ADMIN_EMAIL; // Using the constant from config.php
    
    if ($student && !empty($student['email'])) {
        $mail = new PHPMailer(true);

        try {
            // Server Settings (Reusing your existing config)
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com'; 
            $mail->SMTPAuth   = true; 
            
           
            $mail->Username   = 'ae202403345@wmsu.edu.ph'; 
            $mail->Password   = 'snab sflg fgfz cbhm'; 
          

            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; 
            $mail->Port       = 587; 

            // This option is good practice for testing/development
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];

            // Sender Information
            $mail->setFrom('no-reply@yourquizsystem.com', 'Quiz Admin');
            
            // Content Variables
            $recipient_name = htmlspecialchars($student['name']);
            $quiz_title = htmlspecialchars($quiz_data['title']);
            $total_questions = count($quiz_questions);
            $completion_time = date('Y-m-d H:i');


            // 1. --- Student-specific content ---
            $student_bodyContent = "<h2>Hello {$recipient_name}!</h2>";
            $student_bodyContent .= "<p>You have successfully completed the quiz: <strong>{$quiz_title}</strong>.</p>";
            $student_bodyContent .= "<h3>Your Score: {$score} / {$total_questions}</h3>";
            $student_bodyContent .= "<p>Time of Completion: {$completion_time}</p>";
            $student_bodyContent .= "<hr>";
            $student_bodyContent .= "<p><em>Log in to your dashboard to see your full results and ranking.</em></p>";
            
            // 2. --- Admin-specific content (Notification) ---
            $admin_bodyContent = "
                <!DOCTYPE html>
                <html>
                <head>
                    <style>
                        body { font-family: sans-serif; background-color: #f7f7f7; }
                        .container { max-width: 600px; margin: 20px auto; background-color: #ffffff; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
                        .header { background-color: #007bff; color: white; padding: 15px; text-align: center; }
                        .content { padding: 20px; }
                        .score { font-size: 28px; font-weight: bold; color: #28a745; display: block; margin: 10px 0; }
                        .detail-label { font-weight: bold; color: #555; display: inline-block; width: 120px; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>New Quiz Submission Received</h2>
                        </div>
                        <div class='content'>
                            <p><span class='detail-label'>Quiz Title:</span> {$quiz_title}</p>
                            <p><span class='detail-label'>Student:</span> {$recipient_name} ({$student['email']})</p>
                            <p style='margin-top: 20px;'><span class='detail-label'>Score:</span> <span class='score'>{$score} / {$total_questions}</span></p>
                            <p><span class='detail-label'>Completed At:</span> {$completion_time}</p>
                            <p style='margin-top: 30px; font-size: 0.9em; color: #777;'>
                                Log in to the Admin Dashboard for full results.
                            </p>
                        </div>
                    </div>
                </body>
                </html>
            ";


            // --- A. Send the Student Email ---
            $mail->clearAllRecipients(); // Ensure a clean slate
            $mail->addAddress($student['email'], $student['name']);
            $mail->isHTML(true); 
            $mail->Subject = "Your Quiz Results: " . $quiz_data['title'];
            $mail->Body = $student_bodyContent;
            $mail->AltBody = strip_tags($student_bodyContent); 
            $mail->send();

            // --- B. Send the Admin Email ---
            if (filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
                $mail->clearAllRecipients(); // Reset for the next recipient
                $mail->addAddress($admin_email, 'Quiz System Admin');
                $mail->isHTML(true); 
                $mail->Subject = "[ADMIN] New Quiz Submission: " . $quiz_data['title'];
                $mail->Body = $admin_bodyContent;
                $mail->AltBody = "New Quiz Submission - Student: {$recipient_name} ({$student['email']}), Score: {$score}/{$total_questions}."; 
                $mail->send();
            }

            
        } catch (Exception $e) {
            // Log error silently so the user is still redirected successfully
            error_log("Quiz email notification failed. Mailer Error: {$mail->ErrorInfo}");
        }
    }
    // =========================================================
    // END EMAIL NOTIFICATION CODE
    // =========================================================
    
    // Store results in session for display on dashboard
    $_SESSION['quiz_submitted'] = true;
    $_SESSION['last_quiz_score'] = $score;
    $_SESSION['last_quiz_total'] = count($quiz_questions); 
    $_SESSION['last_quiz_title'] = $quiz_data['title']; 

    // Redirect to show results/dashboard
    redirect('student_dashboard.php');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo e($quiz_data['title']); ?> - Quiz</title>
    <link rel="stylesheet" href="styles.css">
    
    <script src="quiz_navigation.js" defer></script>
</head>
<body>
    <div class="header">
        <h2><?php echo e($quiz_data['title']); ?></h2>
        <a href="student_dashboard.php" class="button secondary">Back to Dashboard</a>
    </div>

    <div class="container wide quiz-view">
        <p class="quiz-desc"><?php echo e($quiz_data['description']); ?></p>

        <form method="post" id="quiz-form">
            
            <div id="question-tracker" class="question-tracker"></div>
            
            <div id="questions-wrapper">
                <?php $q_index = 0; ?>
                <?php foreach ($quiz_questions as $q_id => $q_data): ?>
                    <div class="question-block" data-question-index="<?php echo $q_index; ?>"> 
                        <div class="question-header">
                            <span class="q-number">Question <?php echo $q_index + 1; ?>:</span>
                            <h3 class="q-text"><?php echo e($q_data['text']); ?></h3>
                        </div>

                        <div class="answers-group">
                            <?php foreach ($q_data['answers'] as $answer): ?>
                                <label class="answer-option">
                                    <input type="radio" name="q<?php echo e($q_id); ?>" value="<?php echo e($answer['id']); ?>" required>
                                    <span class="answer-text"><?php echo e($answer['text']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php $q_index++; ?>
                <?php endforeach; ?>
            </div>

            <div class="actions center-actions quiz-nav-buttons">
                <button type="button" id="prev-btn" class="button secondary large-button" style="display: none;">Previous</button>
                <button type="button" id="next-btn" class="button primary large-button">Next Question</button>
                
                <button type="submit" id="finish-btn" class="button success large-button" style="display: none;">Finish Quiz and Submit</button>
                
                <input type="hidden" name="action" value="finish">
            </div>
            
        </form>
    </div>
</body>
</html>