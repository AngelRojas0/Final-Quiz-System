<?php
require_once 'config.php';
require_role('student');

$quiz_id = intval($_GET['quiz_id'] ?? 0);
$user_id = $_SESSION['user_id'];
$quiz_title = "Study Guide";
$results_data = [];

if ($quiz_id === 0) {
   
    header('Location: student_dashboard.php?message=Invalid+quiz+ID.');
    exit;
}


try {
    $data_stmt = $pdo->prepare("
        SELECT 
            q.question_text,
            a.answer_text AS correct_answer_text,
            qz.title AS quiz_title
        FROM questions q
        JOIN answers a ON a.question_id = q.id AND a.is_correct = 1
        JOIN quizzes qz ON qz.id = q.quiz_id
        WHERE q.quiz_id = ?
        ORDER BY q.id
    ");
    $data_stmt->execute([$quiz_id]);
    $results_data = $data_stmt->fetchAll();
} catch (PDOException $e) {
    
    error_log("Database error in generate_printables.php: " . $e->getMessage());
    header('Location: student_dashboard.php?message=Error+fetching+quiz+data.');
    exit;
}


if (empty($results_data)) {
    
    die("No questions found for this quiz.");
}

$quiz_title = htmlspecialchars($results_data[0]['quiz_title'] ?? 'Study Guide');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $quiz_title; ?> - Study Guide</title>
    <style>
        /* General Styling for screen display */
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f0f4f8; 
            color: #333;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        h1 {
            color: #007bff;
            border-bottom: 3px solid #e0e0e0;
            padding-bottom: 10px;
            margin-bottom: 25px;
            text-align: center;
        }
        .question-item {
            margin-bottom: 25px;
            border-left: 5px solid #28a745; 
            padding-left: 15px;
            background-color: #f9f9f9;
            padding: 10px;
            border-radius: 4px;
        }
        .question-text {
            font-weight: bold;
            margin-bottom: 5px;
            font-size: 1.1em;
            color: #0056b3;
        }
        .answer-text {
            color: #28a745;
            font-style: italic;
            font-weight: 600;
        }
        .no-print {
            text-align: center;
            margin-top: 20px;
            margin-bottom: 30px;
            padding: 15px;
            border: 1px dashed #ccc;
            border-radius: 5px;
        }
        .print-button {
            padding: 12px 25px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1em;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            transition: background-color 0.3s;
        }
        .print-button:hover {
            background-color: #0056b3;
        }

      
        @media print {
            .no-print {
                display: none !important; 
            }
            .container {
                box-shadow: none;
                padding: 0;
                margin: 0;
                max-width: 100%;
            }
            body {
                padding: 0;
                background-color: white;
            }
            .question-item {
              
                break-inside: avoid;
                border-left: 3px solid #28a745; 
                background-color: #fff;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><?php echo $quiz_title; ?> Study Guide</h1>
        <p style="text-align: center; font-size: 1.1em; margin-bottom: 20px;">This guide contains all quiz questions and their official correct answers.</p>

        <div class="no-print">
            <button class="print-button" onclick="window.print()">
                Open Print Dialog (Save as PDF)
            </button>
            <p style="font-size: 0.9em; color: #555; margin-top: 10px;">
                *In the print dialog that opens, select **'Save as PDF'** from the destination menu to download your file.
            </p>
        </div>

        <?php foreach ($results_data as $i => $row): ?>
            <div class="question-item">
                <p class="question-text">Question <?php echo ($i + 1) . ' of ' . count($results_data) . ': ' . htmlspecialchars($row['question_text']); ?></p>
                <p><strong>Correct Answer:</strong> <span class="answer-text"><?php echo htmlspecialchars($row['correct_answer_text']); ?></span></p>
            </div>
        <?php endforeach; ?>
    </div>
    
    <script>
        window.onload = function() {
          
             setTimeout(function() {
                 window.print();
             }, 500);
        };
    </script>
</body>
</html>