<?php
require_once 'config.php';
require_role('teacher');

$user_id = $_SESSION['user_id'];

// --- Helper Functions ---
if (!function_exists('e')) {
    function e($value) {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('set_notification')) {
    function set_notification($message, $type = 'success') {
        $_SESSION['flash_message'] = ['text' => $message, 'type' => $type];
    }
}

if (!function_exists('format_duration')) {
    function format_duration($seconds) {
        if ($seconds === null || $seconds === 0) return '0s';
        $seconds = (int)$seconds;
        $h = floor($seconds / 3600);
        $m = floor(($seconds % 3600) / 60);
        $s = $seconds % 60;
        $parts = [];
        if ($h > 0) $parts[] = $h . 'h';
        if ($m > 0) $parts[] = $m . 'm';
        if ($s > 0 || empty($parts)) $parts[] = $s . 's';
        return implode(' ', $parts);
    }
}

// --- Generate a random join code ---
function generate_join_code($length = 6) {
    return strtoupper(substr(bin2hex(random_bytes(4)), 0, $length));
}

// =================================================================
// POST ACTION HANDLERS
// =================================================================

$message = '';
$message_type = 'success';

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message']['text'];
    $message_type = $_SESSION['flash_message']['type'];
    unset($_SESSION['flash_message']);
}

// --- Handle Quiz Creation ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // CREATE QUIZ
    if ($action === 'create_quiz') {
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $quiz_type   = $_POST['quiz_type'] ?? 'multiple_choice';
        $time_limit  = (int)($_POST['time_limit'] ?? 0);
        $join_code   = generate_join_code();

        if (empty($title)) {
            set_notification('Quiz title is required.', 'danger');
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO quizzes (title, description, quiz_type, time_limit, join_code, teacher_id, is_active, created_at)
                    VALUES (:title, :description, :quiz_type, :time_limit, :join_code, :teacher_id, 0, NOW())
                ");
                $stmt->execute([
                    ':title'       => $title,
                    ':description' => $description,
                    ':quiz_type'   => $quiz_type,
                    ':time_limit'  => $time_limit,
                    ':join_code'   => $join_code,
                    ':teacher_id'  => $user_id,
                ]);
                $new_quiz_id = $pdo->lastInsertId();
                set_notification("Quiz <strong>" . e($title) . "</strong> created! Join code: <strong>{$join_code}</strong>", 'success');
            } catch (PDOException $ex) {
                set_notification('Database error: ' . e($ex->getMessage()), 'danger');
            }
        }
        header('Location: teacher_dashboard.php');
        exit;
    }

    // ADD QUESTION
    if ($action === 'add_question') {
        $quiz_id       = (int)($_POST['quiz_id'] ?? 0);
        $question_text = trim($_POST['question_text'] ?? '');
        $question_type = $_POST['question_type'] ?? 'multiple_choice';
        $points        = (int)($_POST['points'] ?? 1);

        if ($quiz_id && !empty($question_text)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO questions (quiz_id, question_text, question_type, points)
                    VALUES (:quiz_id, :question_text, :question_type, :points)
                ");
                $stmt->execute([
                    ':quiz_id'       => $quiz_id,
                    ':question_text' => $question_text,
                    ':question_type' => $question_type,
                    ':points'        => $points,
                ]);
                $question_id = $pdo->lastInsertId();

                // Handle answer options for multiple choice and matching
                if ($question_type === 'multiple_choice' && isset($_POST['choices'])) {
                    $correct_index = (int)($_POST['correct_choice'] ?? 0);
                    foreach ($_POST['choices'] as $i => $choice_text) {
                        $choice_text = trim($choice_text);
                        if ($choice_text === '') continue;
                        $is_correct = ($i == $correct_index) ? 1 : 0;
                        $stmt2 = $pdo->prepare("
                            INSERT INTO answer_choices (question_id, choice_text, is_correct)
                            VALUES (:qid, :text, :correct)
                        ");
                        $stmt2->execute([':qid' => $question_id, ':text' => $choice_text, ':correct' => $is_correct]);
                    }
                }

                if ($question_type === 'matching' && isset($_POST['match_left']) && isset($_POST['match_right'])) {
                    foreach ($_POST['match_left'] as $i => $left) {
                        $left  = trim($left);
                        $right = trim($_POST['match_right'][$i] ?? '');
                        if ($left === '' || $right === '') continue;
                        $stmt3 = $pdo->prepare("
                            INSERT INTO matching_pairs (question_id, left_item, right_item)
                            VALUES (:qid, :left, :right)
                        ");
                        $stmt3->execute([':qid' => $question_id, ':left' => $left, ':right' => $right]);
                    }
                }

                if ($question_type === 'identification' && isset($_POST['answer_key'])) {
                    $answer_key = trim($_POST['answer_key']);
                    $stmt4 = $pdo->prepare("
                        INSERT INTO answer_choices (question_id, choice_text, is_correct)
                        VALUES (:qid, :text, 1)
                    ");
                    $stmt4->execute([':qid' => $question_id, ':text' => $answer_key]);
                }

                set_notification('Question added successfully!', 'success');
            } catch (PDOException $ex) {
                set_notification('Error adding question: ' . e($ex->getMessage()), 'danger');
            }
        } else {
            set_notification('Quiz and question text are required.', 'danger');
        }
        header("Location: teacher_dashboard.php?view_quiz={$quiz_id}");
        exit;
    }

    // TOGGLE QUIZ STATUS
    if ($action === 'toggle_quiz') {
        $quiz_id   = (int)($_POST['quiz_id'] ?? 0);
        $new_status = (int)($_POST['new_status'] ?? 0);
        $stmt = $pdo->prepare("UPDATE quizzes SET is_active = ? WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$new_status, $quiz_id, $user_id]);
        $label = $new_status ? 'Activated' : 'Deactivated';
        set_notification("Quiz #{$quiz_id} is now <strong>{$label}</strong>.", 'success');
        header('Location: teacher_dashboard.php');
        exit;
    }

    // REGENERATE JOIN CODE
    if ($action === 'regen_code') {
        $quiz_id  = (int)($_POST['quiz_id'] ?? 0);
        $new_code = generate_join_code();
        $stmt = $pdo->prepare("UPDATE quizzes SET join_code = ? WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$new_code, $quiz_id, $user_id]);
        set_notification("New join code for Quiz #{$quiz_id}: <strong>{$new_code}</strong>", 'success');
        header('Location: teacher_dashboard.php');
        exit;
    }

    // DELETE QUIZ
    if ($action === 'delete_quiz') {
        $quiz_id = (int)($_POST['quiz_id'] ?? 0);
        $pdo->prepare("DELETE FROM quizzes WHERE id = ? AND teacher_id = ?")->execute([$quiz_id, $user_id]);
        set_notification("Quiz #{$quiz_id} deleted.", 'warning');
        header('Location: teacher_dashboard.php');
        exit;
    }

    // DELETE QUESTION
    if ($action === 'delete_question') {
        $question_id = (int)($_POST['question_id'] ?? 0);
        $quiz_id     = (int)($_POST['quiz_id'] ?? 0);
        $pdo->prepare("DELETE FROM questions WHERE id = ?")->execute([$question_id]);
        set_notification("Question deleted.", 'warning');
        header("Location: teacher_dashboard.php?view_quiz={$quiz_id}");
        exit;
    }
}

// =================================================================
// DATA FETCHING
// =================================================================

// Fetch teacher's quizzes
$quizzes = $pdo->prepare("SELECT * FROM quizzes WHERE teacher_id = ? ORDER BY id DESC");
$quizzes->execute([$user_id]);
$all_quizzes = $quizzes->fetchAll();

// Fetch results for this teacher's quizzes
$results_stmt = $pdo->prepare("
    SELECT 
        r.id AS result_id,
        u.name AS student_name,
        q.title AS quiz_title,
        q.id AS quiz_id,
        r.score,
        r.is_finished,
        r.start_time,
        r.end_time,
        TIMESTAMPDIFF(SECOND, r.start_time, r.end_time) AS duration_seconds
    FROM results r
    JOIN users u ON r.user_id = u.id
    JOIN quizzes q ON r.quiz_id = q.id
    WHERE q.teacher_id = ?
    ORDER BY r.is_finished ASC, r.start_time DESC
");
$results_stmt->execute([$user_id]);
$all_results = $results_stmt->fetchAll();

// KPIs
$total_quizzes   = count($all_quizzes);
$finished        = array_filter($all_results, fn($r) => (bool)$r['is_finished']);
$finished_count  = count($finished);
$avg_score       = $finished_count ? round(array_sum(array_column(iterator_to_array((function() use ($finished) { foreach($finished as $f) yield $f; })(), false), 'score')) / $finished_count, 1) : 'N/A';

// If viewing a specific quiz's questions
$view_quiz_id = isset($_GET['view_quiz']) ? (int)$_GET['view_quiz'] : 0;
$view_quiz    = null;
$quiz_questions = [];

if ($view_quiz_id) {
    $vq = $pdo->prepare("SELECT * FROM quizzes WHERE id = ? AND teacher_id = ?");
    $vq->execute([$view_quiz_id, $user_id]);
    $view_quiz = $vq->fetch();

    if ($view_quiz) {
        $qq = $pdo->prepare("SELECT * FROM questions WHERE quiz_id = ? ORDER BY id ASC");
        $qq->execute([$view_quiz_id]);
        $quiz_questions = $qq->fetchAll();

        foreach ($quiz_questions as &$q) {
            if ($q['question_type'] === 'multiple_choice' || $q['question_type'] === 'identification') {
                $ac = $pdo->prepare("SELECT * FROM answer_choices WHERE question_id = ?");
                $ac->execute([$q['id']]);
                $q['choices'] = $ac->fetchAll();
            }
            if ($q['question_type'] === 'matching') {
                $mp = $pdo->prepare("SELECT * FROM matching_pairs WHERE question_id = ?");
                $mp->execute([$q['id']]);
                $q['pairs'] = $mp->fetchAll();
            }
        }
        unset($q);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard – Quiz System</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="modal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <style>
        /* =========================================================
           TEACHER DASHBOARD – EXTRA STYLES
           ========================================================= */

        /* Quiz Type Badge */
        .badge-type {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
            letter-spacing: 0.03em;
        }
        .badge-mc   { background: #dbeafe; color: #1d4ed8; }
        .badge-essay{ background: #fef9c3; color: #92400e; }
        .badge-id   { background: #dcfce7; color: #15803d; }
        .badge-match{ background: #f3e8ff; color: #7e22ce; }
        .badge-mixed{ background: #ffe4e6; color: #be123c; }

        /* Quiz cards row */
        .quiz-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.2rem;
        }

        .quiz-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.2rem 1.4rem;
            display: flex;
            flex-direction: column;
            gap: .6rem;
            transition: box-shadow .2s;
        }
        .quiz-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.1); }
        .quiz-card-header { display: flex; justify-content: space-between; align-items: flex-start; gap: .5rem; }
        .quiz-card-title  { font-size: 1rem; font-weight: 700; color: #111827; margin: 0; }
        .quiz-card-desc   { font-size: .85rem; color: #6b7280; }

        .join-code-box {
            display: flex;
            align-items: center;
            gap: .5rem;
            background: #f3f4f6;
            border-radius: 8px;
            padding: .4rem .8rem;
        }
        .join-code-box .code {
            font-family: 'Courier New', monospace;
            font-size: 1.1rem;
            font-weight: 700;
            letter-spacing: .15em;
            color: #1d4ed8;
        }
        .copy-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #6b7280;
            font-size: .9rem;
            padding: 0 .3rem;
            transition: color .15s;
        }
        .copy-btn:hover { color: #1d4ed8; }

        .quiz-card-actions { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .3rem; }

        /* Question builder */
        .question-builder {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
        }
        .question-type-tabs {
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .q-tab {
            padding: .4rem 1rem;
            border: 2px solid #e5e7eb;
            border-radius: 20px;
            cursor: pointer;
            font-size: .85rem;
            font-weight: 600;
            background: #f9fafb;
            transition: all .15s;
        }
        .q-tab.active, .q-tab:hover { border-color: #4f46e5; background: #eef2ff; color: #4338ca; }

        .q-panel { display: none; }
        .q-panel.active { display: block; }

        /* Answer choices */
        .choice-row {
            display: flex;
            align-items: center;
            gap: .5rem;
            margin-bottom: .5rem;
        }
        .choice-row input[type="text"] { flex: 1; }

        /* Matching pairs */
        .pair-row {
            display: grid;
            grid-template-columns: 1fr auto 1fr auto;
            align-items: center;
            gap: .5rem;
            margin-bottom: .5rem;
        }
        .pair-arrow { color: #9ca3af; font-size: 1.1rem; }

        /* Question list */
        .question-item {
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 1rem 1.2rem;
            margin-bottom: .8rem;
            background: #fafafa;
        }
        .question-item .q-meta {
            display: flex;
            gap: .6rem;
            align-items: center;
            margin-top: .4rem;
            font-size: .8rem;
            color: #6b7280;
        }

        /* Tabs for main sections */
        .dashboard-tabs {
            display: flex;
            gap: .3rem;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .dash-tab {
            padding: .6rem 1.2rem;
            border: none;
            background: none;
            cursor: pointer;
            font-size: .9rem;
            font-weight: 600;
            color: #6b7280;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all .15s;
        }
        .dash-tab.active, .dash-tab:hover {
            color: #4f46e5;
            border-bottom-color: #4f46e5;
        }

        .dash-section { display: none; }
        .dash-section.active { display: block; }

        /* Status pill */
        .status-pill {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 20px;
            font-size: .75rem;
            font-weight: 700;
        }
        .status-pill.active   { background: #dcfce7; color: #15803d; }
        .status-pill.inactive { background: #f3f4f6; color: #6b7280; }

        /* KPI grid (reuse from admin) */
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; }
        .kpi-item { background: #f9fafb; border-radius: 10px; padding: 1rem; text-align: center; }
        .kpi-value { display: block; font-size: 2rem; font-weight: 800; color: #4f46e5; }
        .kpi-label { display: block; font-size: .78rem; color: #6b7280; margin-top: .2rem; }

        /* Create quiz form */
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }

        .modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: #fff;
            border-radius: 14px;
            padding: 2rem;
            width: min(680px, 95vw);
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        .modal-box h3 { margin-top: 0; }
        .modal-close {
            position: absolute; top: 1rem; right: 1rem;
            background: none; border: none; font-size: 1.4rem;
            cursor: pointer; color: #6b7280;
        }

        /* Results table */
        .results-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        .results-table th { background: #f3f4f6; padding: .6rem .8rem; text-align: left; font-weight: 600; color: #374151; border-bottom: 2px solid #e5e7eb; }
        .results-table td { padding: .6rem .8rem; border-bottom: 1px solid #f3f4f6; color: #374151; }
        .results-table tr:last-child td { border-bottom: none; }
        .results-table .in-progress { color: #d97706; font-style: italic; }
    </style>
</head>
<body class="teacher">

    <!-- ============================================================
         HEADER
         ============================================================ -->
    <div class="header">
        <h2><i class="fas fa-chalkboard-teacher"></i> Teacher Dashboard &mdash; Welcome, <?php echo e($_SESSION['name']); ?></h2>
        <div class="header-actions">
            <button class="button" onclick="openModal('createQuizModal')">
                <i class="fas fa-plus"></i> New Quiz
            </button>
            <a href="#" class="button danger logout-trigger" onclick="showLogoutModal(); return false;">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <?php include 'components/logout_modal.php'; ?>

    <div class="container wide">

        <!-- Flash message -->
        <?php if ($message): ?>
            <div class="msg <?php echo e($message_type); ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if ($view_quiz && !empty($view_quiz)): ?>
        <!-- ============================================================
             QUIZ BUILDER VIEW
             ============================================================ -->
        <div style="margin-bottom:1rem;">
            <a href="teacher_dashboard.php" class="button" style="background:#f3f4f6;color:#374151;">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <div class="card" style="margin-bottom:1.5rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
                <div>
                    <h2 style="margin:0 0 .3rem;"><?php echo e($view_quiz['title']); ?></h2>
                    <p style="margin:0;color:#6b7280;"><?php echo e($view_quiz['description']); ?></p>
                    <div style="display:flex;gap:.6rem;align-items:center;margin-top:.6rem;flex-wrap:wrap;">
                        <?php
                            $type_map = [
                                'multiple_choice' => ['label'=>'Multiple Choice','class'=>'badge-mc'],
                                'essay'           => ['label'=>'Essay','class'=>'badge-essay'],
                                'identification'  => ['label'=>'Identification','class'=>'badge-id'],
                                'matching'        => ['label'=>'Matching','class'=>'badge-match'],
                                'mixed'           => ['label'=>'Mixed','class'=>'badge-mixed'],
                            ];
                            $t = $type_map[$view_quiz['quiz_type']] ?? ['label'=>$view_quiz['quiz_type'],'class'=>'badge-mc'];
                        ?>
                        <span class="badge-type <?php echo $t['class']; ?>"><?php echo $t['label']; ?></span>
                        <span class="status-pill <?php echo $view_quiz['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $view_quiz['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                        <?php if ($view_quiz['time_limit']): ?>
                            <span style="font-size:.82rem;color:#6b7280;"><i class="fas fa-clock"></i> <?php echo (int)$view_quiz['time_limit']; ?> min</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="join-code-box" style="flex-shrink:0;">
                    <i class="fas fa-key" style="color:#4f46e5;"></i>
                    <span style="font-size:.8rem;color:#6b7280;">Join Code:</span>
                    <span class="code" id="quiz-code-<?php echo $view_quiz['id']; ?>"><?php echo e($view_quiz['join_code']); ?></span>
                    <button class="copy-btn" onclick="copyCode('quiz-code-<?php echo $view_quiz['id']; ?>')" title="Copy code">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Add Question Panel -->
        <div class="card question-builder" style="margin-bottom:1.5rem;">
            <h3><i class="fas fa-plus-circle"></i> Add Question</h3>

            <div class="question-type-tabs">
                <button type="button" class="q-tab active" onclick="switchQType('mc')">
                    <i class="fas fa-list-ul"></i> Multiple Choice
                </button>
                <button type="button" class="q-tab" onclick="switchQType('essay')">
                    <i class="fas fa-pen"></i> Essay
                </button>
                <button type="button" class="q-tab" onclick="switchQType('identification')">
                    <i class="fas fa-font"></i> Identification
                </button>
                <button type="button" class="q-tab" onclick="switchQType('matching')">
                    <i class="fas fa-link"></i> Matching
                </button>
            </div>

            <!-- ---- MULTIPLE CHOICE ---- -->
            <div id="panel-mc" class="q-panel active">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_question">
                    <input type="hidden" name="quiz_id" value="<?php echo $view_quiz['id']; ?>">
                    <input type="hidden" name="question_type" value="multiple_choice">

                    <div class="form-group">
                        <label>Question <span style="color:red">*</span></label>
                        <input type="text" name="question_text" placeholder="Enter your question…" required>
                    </div>

                    <div class="form-group">
                        <label>Points</label>
                        <input type="number" name="points" value="1" min="1" max="100" style="width:80px;">
                    </div>

                    <label style="font-weight:600;display:block;margin-bottom:.4rem;">Answer Choices <small style="font-weight:400;color:#6b7280;">(mark the correct one)</small></label>
                    <div id="mc-choices">
                        <?php for($i=0;$i<4;$i++): ?>
                        <div class="choice-row">
                            <input type="radio" name="correct_choice" value="<?php echo $i; ?>" <?php if($i===0) echo 'checked'; ?> title="Correct answer">
                            <input type="text" name="choices[]" placeholder="Choice <?php echo $i+1; ?>" required>
                            <?php if($i>=2): ?>
                            <button type="button" class="copy-btn" onclick="this.closest('.choice-row').remove()" title="Remove"><i class="fas fa-times"></i></button>
                            <?php endif; ?>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <button type="button" class="button" style="margin-bottom:1rem;font-size:.82rem;padding:.3rem .8rem;" onclick="addChoice()">
                        <i class="fas fa-plus"></i> Add Choice
                    </button>

                    <div>
                        <button type="submit" class="button success"><i class="fas fa-save"></i> Save Question</button>
                    </div>
                </form>
            </div>

            <!-- ---- ESSAY ---- -->
            <div id="panel-essay" class="q-panel">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_question">
                    <input type="hidden" name="quiz_id" value="<?php echo $view_quiz['id']; ?>">
                    <input type="hidden" name="question_type" value="essay">
                    <div class="form-group">
                        <label>Question <span style="color:red">*</span></label>
                        <textarea name="question_text" rows="3" placeholder="Enter your essay question prompt…" required></textarea>
                    </div>
                    <div class="form-group">
                        <label>Points</label>
                        <input type="number" name="points" value="5" min="1" max="100" style="width:80px;">
                    </div>
                    <p style="font-size:.83rem;color:#6b7280;"><i class="fas fa-info-circle"></i> Essay questions are manually graded.</p>
                    <button type="submit" class="button success"><i class="fas fa-save"></i> Save Question</button>
                </form>
            </div>

            <!-- ---- IDENTIFICATION ---- -->
            <div id="panel-identification" class="q-panel">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_question">
                    <input type="hidden" name="quiz_id" value="<?php echo $view_quiz['id']; ?>">
                    <input type="hidden" name="question_type" value="identification">
                    <div class="form-group">
                        <label>Question <span style="color:red">*</span></label>
                        <input type="text" name="question_text" placeholder="e.g. What is the capital of France?" required>
                    </div>
                    <div class="form-group">
                        <label>Answer Key <span style="color:red">*</span></label>
                        <input type="text" name="answer_key" placeholder="Exact answer (case-insensitive match)" required>
                    </div>
                    <div class="form-group">
                        <label>Points</label>
                        <input type="number" name="points" value="1" min="1" max="100" style="width:80px;">
                    </div>
                    <button type="submit" class="button success"><i class="fas fa-save"></i> Save Question</button>
                </form>
            </div>

            <!-- ---- MATCHING ---- -->
            <div id="panel-matching" class="q-panel">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="add_question">
                    <input type="hidden" name="quiz_id" value="<?php echo $view_quiz['id']; ?>">
                    <input type="hidden" name="question_type" value="matching">
                    <div class="form-group">
                        <label>Instruction / Question</label>
                        <input type="text" name="question_text" value="Match the following:" required>
                    </div>
                    <div class="form-group">
                        <label>Points (total for this question)</label>
                        <input type="number" name="points" value="4" min="1" max="100" style="width:80px;">
                    </div>

                    <label style="font-weight:600;display:block;margin-bottom:.5rem;">Pairs</label>
                    <div id="match-pairs">
                        <?php for($i=0;$i<4;$i++): ?>
                        <div class="pair-row">
                            <input type="text" name="match_left[]" placeholder="Left item <?php echo $i+1; ?>" required>
                            <span class="pair-arrow"><i class="fas fa-arrows-alt-h"></i></span>
                            <input type="text" name="match_right[]" placeholder="Right item <?php echo $i+1; ?>" required>
                            <?php if($i>=2): ?>
                            <button type="button" class="copy-btn" onclick="this.closest('.pair-row').remove()" title="Remove"><i class="fas fa-times"></i></button>
                            <?php else: ?>
                            <span></span>
                            <?php endif; ?>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <button type="button" class="button" style="margin-bottom:1rem;font-size:.82rem;padding:.3rem .8rem;" onclick="addPair()">
                        <i class="fas fa-plus"></i> Add Pair
                    </button>

                    <div>
                        <button type="submit" class="button success"><i class="fas fa-save"></i> Save Question</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Question List -->
        <div class="card">
            <h3><i class="fas fa-list-ol"></i> Questions (<?php echo count($quiz_questions); ?>)</h3>

            <?php if (empty($quiz_questions)): ?>
                <p style="color:#9ca3af;text-align:center;padding:2rem 0;">No questions yet. Use the builder above to add some!</p>
            <?php else: ?>
                <?php foreach ($quiz_questions as $num => $q): ?>
                <div class="question-item">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
                        <div style="flex:1;">
                            <strong>#<?php echo $num+1; ?>.</strong> <?php echo e($q['question_text']); ?>
                            <div class="q-meta">
                                <?php
                                    $qt = $type_map[$q['question_type']] ?? ['label'=>$q['question_type'],'class'=>'badge-mc'];
                                ?>
                                <span class="badge-type <?php echo $qt['class']; ?>"><?php echo $qt['label']; ?></span>
                                <span><i class="fas fa-star"></i> <?php echo (int)$q['points']; ?> pt(s)</span>
                            </div>

                            <?php if ($q['question_type'] === 'multiple_choice' && !empty($q['choices'])): ?>
                                <ul style="margin:.5rem 0 0 1rem;font-size:.83rem;color:#374151;">
                                    <?php foreach ($q['choices'] as $c): ?>
                                        <li style="<?php echo $c['is_correct'] ? 'color:#15803d;font-weight:600;' : ''; ?>">
                                            <?php echo $c['is_correct'] ? '✔ ' : '○ '; ?><?php echo e($c['choice_text']); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>

                            <?php if ($q['question_type'] === 'identification' && !empty($q['choices'])): ?>
                                <p style="font-size:.83rem;margin:.4rem 0 0;color:#15803d;"><strong>Answer:</strong> <?php echo e($q['choices'][0]['choice_text']); ?></p>
                            <?php endif; ?>

                            <?php if ($q['question_type'] === 'essay'): ?>
                                <p style="font-size:.83rem;margin:.4rem 0 0;color:#92400e;"><em>Open-ended — manually graded</em></p>
                            <?php endif; ?>

                            <?php if ($q['question_type'] === 'matching' && !empty($q['pairs'])): ?>
                                <table style="font-size:.83rem;margin:.5rem 0 0;border-collapse:collapse;">
                                    <?php foreach ($q['pairs'] as $p): ?>
                                    <tr>
                                        <td style="padding:.15rem .5rem .15rem 0;color:#374151;"><?php echo e($p['left_item']); ?></td>
                                        <td style="padding:.15rem .4rem;color:#9ca3af;">↔</td>
                                        <td style="padding:.15rem 0;color:#374151;"><?php echo e($p['right_item']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                            <?php endif; ?>
                        </div>
                        <form method="POST" onsubmit="return confirm('Delete this question?')">
                            <input type="hidden" name="action" value="delete_question">
                            <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                            <input type="hidden" name="quiz_id" value="<?php echo $view_quiz['id']; ?>">
                            <button type="submit" class="button danger" style="padding:.3rem .7rem;font-size:.8rem;">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- ============================================================
             MAIN DASHBOARD VIEW
             ============================================================ -->

        <!-- KPI Row -->
        <div class="card kpi-report-card" style="margin-bottom:1.5rem;">
            <h3><i class="fas fa-chart-bar"></i> Overview</h3>
            <div class="kpi-grid">
                <div class="kpi-item">
                    <span class="kpi-value"><?php echo $total_quizzes; ?></span>
                    <span class="kpi-label">My Quizzes</span>
                </div>
                <div class="kpi-item">
                    <span class="kpi-value"><?php echo count($all_results); ?></span>
                    <span class="kpi-label">Total Attempts</span>
                </div>
                <div class="kpi-item">
                    <span class="kpi-value kpi-success"><?php echo $finished_count; ?></span>
                    <span class="kpi-label">Finished</span>
                </div>
                <div class="kpi-item">
                    <span class="kpi-value"><?php echo is_numeric($avg_score) ? $avg_score . '%' : $avg_score; ?></span>
                    <span class="kpi-label">Avg Score</span>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="dashboard-tabs">
            <button class="dash-tab active" onclick="switchTab('quizzes', this)"><i class="fas fa-book-open"></i> My Quizzes</button>
            <button class="dash-tab" onclick="switchTab('results', this)"><i class="fas fa-poll"></i> Student Results</button>
        </div>

        <!-- === QUIZZES SECTION === -->
        <div id="tab-quizzes" class="dash-section active">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
                <h3 style="margin:0;">All Quizzes</h3>
                <button class="button success" onclick="openModal('createQuizModal')">
                    <i class="fas fa-plus"></i> Create Quiz
                </button>
            </div>

            <?php if (empty($all_quizzes)): ?>
                <div class="card" style="text-align:center;padding:3rem 1rem;color:#9ca3af;">
                    <i class="fas fa-inbox" style="font-size:2.5rem;margin-bottom:.8rem;display:block;"></i>
                    <p>No quizzes yet. Create your first quiz!</p>
                    <button class="button success" onclick="openModal('createQuizModal')"><i class="fas fa-plus"></i> Create Quiz</button>
                </div>
            <?php else: ?>
                <div class="quiz-grid">
                    <?php foreach ($all_quizzes as $quiz): ?>
                    <?php
                        $t = $type_map[$quiz['quiz_type']] ?? ['label'=>$quiz['quiz_type'],'class'=>'badge-mc'];
                        $q_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE quiz_id = ?");
                        $q_count_stmt->execute([$quiz['id']]);
                        $q_count = $q_count_stmt->fetchColumn();
                    ?>
                    <div class="quiz-card">
                        <div class="quiz-card-header">
                            <p class="quiz-card-title"><?php echo e($quiz['title']); ?></p>
                            <span class="status-pill <?php echo $quiz['is_active'] ? 'active' : 'inactive'; ?>">
                                <?php echo $quiz['is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>

                        <?php if ($quiz['description']): ?>
                            <p class="quiz-card-desc"><?php echo e($quiz['description']); ?></p>
                        <?php endif; ?>

                        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                            <span class="badge-type <?php echo $t['class']; ?>"><?php echo $t['label']; ?></span>
                            <span style="font-size:.78rem;color:#6b7280;"><i class="fas fa-question-circle"></i> <?php echo $q_count; ?> Qs</span>
                            <?php if ($quiz['time_limit']): ?>
                                <span style="font-size:.78rem;color:#6b7280;"><i class="fas fa-clock"></i> <?php echo (int)$quiz['time_limit']; ?> min</span>
                            <?php endif; ?>
                        </div>

                        <!-- Join Code -->
                        <div class="join-code-box">
                            <i class="fas fa-key" style="color:#4f46e5;font-size:.85rem;"></i>
                            <span style="font-size:.75rem;color:#6b7280;">Code:</span>
                            <span class="code" id="code-<?php echo $quiz['id']; ?>"><?php echo e($quiz['join_code']); ?></span>
                            <button class="copy-btn" onclick="copyCode('code-<?php echo $quiz['id']; ?>')" title="Copy code">
                                <i class="fas fa-copy"></i>
                            </button>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="action" value="regen_code">
                                <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>">
                                <button type="submit" class="copy-btn" title="Regenerate code" onclick="return confirm('Generate a new join code? The old code will stop working.')">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                            </form>
                        </div>

                        <!-- Actions -->
                        <div class="quiz-card-actions">
                            <a href="teacher_dashboard.php?view_quiz=<?php echo $quiz['id']; ?>" class="button" style="font-size:.82rem;padding:.35rem .9rem;">
                                <i class="fas fa-edit"></i> Manage
                            </a>

                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="action" value="toggle_quiz">
                                <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>">
                                <input type="hidden" name="new_status" value="<?php echo $quiz['is_active'] ? 0 : 1; ?>">
                                <button type="submit" class="button <?php echo $quiz['is_active'] ? 'warning' : 'success'; ?>" style="font-size:.82rem;padding:.35rem .9rem;">
                                    <i class="fas fa-<?php echo $quiz['is_active'] ? 'pause' : 'play'; ?>"></i>
                                    <?php echo $quiz['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                </button>
                            </form>

                            <form method="POST" style="margin:0;" onsubmit="return confirm('Delete quiz &quot;<?php echo e($quiz['title']); ?>&quot; and all its questions? This cannot be undone.')">
                                <input type="hidden" name="action" value="delete_quiz">
                                <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>">
                                <button type="submit" class="button danger" style="font-size:.82rem;padding:.35rem .9rem;">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- === RESULTS SECTION === -->
        <div id="tab-results" class="dash-section">
            <h3 style="margin-bottom:1rem;">Student Results</h3>

            <?php if (empty($all_results)): ?>
                <div class="card" style="text-align:center;padding:3rem;color:#9ca3af;">
                    <i class="fas fa-poll" style="font-size:2.5rem;display:block;margin-bottom:.8rem;"></i>
                    <p>No results yet. Share your quiz codes with students!</p>
                </div>
            <?php else: ?>
                <div class="card" style="overflow-x:auto;">
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Student</th>
                                <th>Quiz</th>
                                <th>Score</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_results as $i => $res): ?>
                            <tr>
                                <td><?php echo $i+1; ?></td>
                                <td><?php echo e($res['student_name']); ?></td>
                                <td><?php echo e($res['quiz_title']); ?></td>
                                <td>
                                    <?php if ($res['is_finished']): ?>
                                        <strong><?php echo number_format((float)$res['score'], 1); ?>%</strong>
                                    <?php else: ?>
                                        <span class="in-progress">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $res['duration_seconds'] ? format_duration($res['duration_seconds']) : '—'; ?></td>
                                <td>
                                    <?php if ($res['is_finished']): ?>
                                        <span class="status-pill active">Finished</span>
                                    <?php else: ?>
                                        <span class="status-pill inactive in-progress">In Progress</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $res['start_time'] ? date('M d, Y', strtotime($res['start_time'])) : '—'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php endif; ?>
    </div><!-- /.container -->

    <!-- ============================================================
         CREATE QUIZ MODAL
         ============================================================ -->
    <div class="modal-overlay" id="createQuizModal" onclick="closeModalOnBg(event, 'createQuizModal')">
        <div class="modal-box">
            <button class="modal-close" onclick="closeModal('createQuizModal')" title="Close">&times;</button>
            <h3><i class="fas fa-plus-circle"></i> Create New Quiz</h3>

            <form method="POST" action="">
                <input type="hidden" name="action" value="create_quiz">

                <div class="form-group">
                    <label>Quiz Title <span style="color:red">*</span></label>
                    <input type="text" name="title" placeholder="e.g. Chapter 5 Review" required>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="2" placeholder="Optional description for students…"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Quiz Type <span style="color:red">*</span></label>
                        <select name="quiz_type" required>
                            <option value="multiple_choice">Multiple Choice</option>
                            <option value="essay">Essay</option>
                            <option value="identification">Identification</option>
                            <option value="matching">Matching</option>
                            <option value="mixed">Mixed (All Types)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Time Limit (minutes)</label>
                        <input type="number" name="time_limit" placeholder="0 = no limit" min="0" max="300" value="0">
                    </div>
                </div>

                <p style="font-size:.82rem;color:#6b7280;"><i class="fas fa-info-circle"></i>
                    A unique <strong>Join Code</strong> will be generated automatically. Share it with students to join the quiz.
                </p>

                <div style="display:flex;gap:.7rem;justify-content:flex-end;margin-top:1rem;">
                    <button type="button" class="button" style="background:#f3f4f6;color:#374151;" onclick="closeModal('createQuizModal')">Cancel</button>
                    <button type="submit" class="button success"><i class="fas fa-save"></i> Create Quiz</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============================================================
         JAVASCRIPT
         ============================================================ -->
    <script>
        // --- Tab switching ---
        function switchTab(name, btn) {
            document.querySelectorAll('.dash-section').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.dash-tab').forEach(b => b.classList.remove('active'));
            document.getElementById('tab-' + name).classList.add('active');
            btn.classList.add('active');
        }

        // --- Modal ---
        function openModal(id) { document.getElementById(id).classList.add('open'); }
        function closeModal(id) { document.getElementById(id).classList.remove('open'); }
        function closeModalOnBg(e, id) { if (e.target.id === id) closeModal(id); }

        // --- Question type tabs ---
        function switchQType(type) {
            document.querySelectorAll('.q-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.q-panel').forEach(p => p.classList.remove('active'));
            document.getElementById('panel-' + type).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        // --- Add MC choice ---
        let choiceIndex = 4;
        function addChoice() {
            const container = document.getElementById('mc-choices');
            const row = document.createElement('div');
            row.className = 'choice-row';
            row.innerHTML = `
                <input type="radio" name="correct_choice" value="${choiceIndex}" title="Correct answer">
                <input type="text" name="choices[]" placeholder="Choice ${choiceIndex + 1}" required>
                <button type="button" class="copy-btn" onclick="this.closest('.choice-row').remove()" title="Remove"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(row);
            choiceIndex++;
        }

        // --- Add Matching pair ---
        let pairIndex = 4;
        function addPair() {
            const container = document.getElementById('match-pairs');
            const row = document.createElement('div');
            row.className = 'pair-row';
            row.innerHTML = `
                <input type="text" name="match_left[]" placeholder="Left item ${pairIndex + 1}" required>
                <span class="pair-arrow"><i class="fas fa-arrows-alt-h"></i></span>
                <input type="text" name="match_right[]" placeholder="Right item ${pairIndex + 1}" required>
                <button type="button" class="copy-btn" onclick="this.closest('.pair-row').remove()" title="Remove"><i class="fas fa-times"></i></button>
            `;
            container.appendChild(row);
            pairIndex++;
        }

        // --- Copy join code ---
        function copyCode(elementId) {
            const code = document.getElementById(elementId).innerText;
            navigator.clipboard.writeText(code).then(() => {
                const btn = document.querySelector(`[onclick="copyCode('${elementId}')"]`);
                if (btn) {
                    btn.innerHTML = '<i class="fas fa-check" style="color:#15803d;"></i>';
                    setTimeout(() => btn.innerHTML = '<i class="fas fa-copy"></i>', 1500);
                }
            });
        }

        // --- Logout modal (stub if not already defined) ---
        if (typeof showLogoutModal === 'undefined') {
            function showLogoutModal() {
                if (confirm('Are you sure you want to logout?')) {
                    window.location.href = 'logout.php';
                }
            }
        }
    </script>
</body>
</html>