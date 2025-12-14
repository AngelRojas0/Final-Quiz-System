<?php
require_once 'config.php';
require_role('admin');

$quiz_id = (int)($_GET['quiz_id'] ?? 0);
$message = '';
$error = '';

// --- 1. Fetch Quiz Data ---
$stmt = $pdo->prepare("SELECT * FROM quizzes WHERE id = ?");
$stmt->execute([$quiz_id]);
$quiz = $stmt->fetch();

if (!$quiz) {
    redirect('admin_dashboard.php');
}

// --- 2. Handle Form Submissions (Updates, Adds, Deletes) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // A. Update Quiz Title/Description
    if (isset($_POST['action']) && $_POST['action'] === 'update_quiz') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $stmt = $pdo->prepare("UPDATE quizzes SET title = ?, description = ? WHERE id = ?");
        $stmt->execute([$title, $description, $quiz_id]);
        $message = 'Quiz details updated successfully!';
        // Reload quiz data after update
        redirect('edit_quiz.php?quiz_id=' . $quiz_id); 

       
        
    // B. Add New Question
    } elseif (isset($_POST['action']) && $_POST['action'] === 'add_question') {
        $q_text = trim($_POST['question_text'] ?? '');
        $answers = $_POST['answers'] ?? [];
        $correct_index = (int)($_POST['correct_answer'] ?? 0);

        if (!empty($q_text) && count($answers) >= 2) {
            // Insert Question
            $pdo->prepare("INSERT INTO questions (quiz_id, question_text) VALUES (?, ?)")->execute([$quiz_id, $q_text]);
            $q_id = $pdo->lastInsertId();

            // Insert Answers
            foreach ($answers as $index => $a_text) {
                if (!empty($a_text)) {
                    $is_correct = ($index == $correct_index) ? 1 : 0;
                    $pdo->prepare("INSERT INTO answers (question_id, answer_text, is_correct) VALUES (?, ?, ?)")
                        ->execute([$q_id, $a_text, $is_correct]);
                }
            }
            $message = 'New question added successfully!';
        } else {
            $error = 'Question text and at least two answer options are required.';
        }
    
    // C. Delete Question
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_question') {
        $q_id_to_delete = (int)$_POST['question_id'];
        $pdo->prepare("DELETE FROM questions WHERE id = ? AND quiz_id = ?")->execute([$q_id_to_delete, $quiz_id]);
        $message = 'Question deleted successfully!';
    }
    
}

// --- 3. Fetch Questions and Answers for Display ---
$questions_stmt = $pdo->prepare("
    SELECT q.id AS question_id, q.question_text, a.id AS answer_id, a.answer_text, a.is_correct
    FROM questions q 
    LEFT JOIN answers a ON q.id = a.question_id 
    WHERE q.quiz_id = ? 
    ORDER BY q.id, a.id
");
$questions_stmt->execute([$quiz_id]);
$quiz_questions = [];

// Group answers by question ID
while ($row = $questions_stmt->fetch()) {
    $q_id = $row['question_id'];
    if (!isset($quiz_questions[$q_id])) {
        $quiz_questions[$q_id] = [
            'text' => $row['question_text'],
            'answers' => []
        ];
    }
    $quiz_questions[$q_id]['answers'][] = $row;
}
?>

<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Quiz: <?php echo e($quiz['title']); ?></title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="header">
        <h2>✏️ Editing Quiz: <?php echo e($quiz['title']); ?></h2>
        <a href="admin_dashboard.php" class="button secondary">Back to Dashboard</a>
    </div>

    <div class="container wide">
        <?php if ($error): ?><div class="msg err"><?php echo e($error); ?></div><?php endif; ?>
        <?php if ($message): ?><div class="msg success"><?php echo e($message); ?></div><?php endif; ?>

        <div class="card card-details-editor">
            <h3>Quiz Details</h3>
            <form method="post">
                <input type="hidden" name="action" value="update_quiz">
                <label>Title <input type="text" name="title" value="<?php echo e($quiz['title']); ?>" required></label>
                <label>Description <textarea name="description"><?php echo e($quiz['description']); ?></textarea></label>
                <button type="submit" class="button primary small">Save Details</button>
            </form>
        </div>

        <div class="card card-questions-list">
            <h3>Current Questions (<?php echo count($quiz_questions); ?>)</h3>
            
            <?php if (empty($quiz_questions)): ?>
                <p class="empty-state">This quiz has no questions yet. Use the form below to add some!</p>
            <?php else: ?>
                <?php foreach ($quiz_questions as $q_id => $q_data): ?>
                    <div class="question-item">
                        <div class="q-content">
                            <strong>Q<?php echo array_search($q_id, array_keys($quiz_questions)) + 1; ?>:</strong> <?php echo e($q_data['text']); ?>
                            <ul class="answer-preview">
                                <?php foreach ($q_data['answers'] as $answer): ?>
                                    <li class="<?php echo $answer['is_correct'] ? 'correct-answer' : ''; ?>">
                                        <?php echo $answer['is_correct'] ? '✔️' : '•'; ?> <?php echo e($answer['answer_text']); ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="q-actions">
                            <form method="post" class="inline-form" onsubmit="return confirm('Are you sure you want to delete this question?');">
                                <input type="hidden" name="action" value="delete_question">
                                <input type="hidden" name="question_id" value="<?php echo e($q_id); ?>">
                                <button type="submit" class="button small danger">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <div class="card card-add-question">
            <h3>➕ Add New Question</h3>
            <form method="post">
                <input type="hidden" name="action" value="add_question">
                <label>Question Text <textarea name="question_text" required></textarea></label>

                <h4>Answer Options (Check the circle for the correct one)</h4>
                <div class="answer-inputs">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                        <div class="answer-input-group">
                            <label class="radio-label">
                                <input type="radio" name="correct_answer" value="<?php echo e($i); ?>" <?php echo $i === 0 ? 'required' : ''; ?>> Correct?
                            </label>
                            <input type="text" name="answers[]" placeholder="Option <?php echo e($i + 1); ?>" required>
                        </div>
                    <?php endfor; ?>
                </div>

                <button type="submit" class="button success full-width">Add Question to Quiz</button>
            </form>
        </div>

    </div>
</body>
</html>