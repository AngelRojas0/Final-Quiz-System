document.addEventListener('DOMContentLoaded', () => {
    const questions = document.querySelectorAll('.question-block');
    const prevBtn = document.getElementById('prev-btn');
    const nextBtn = document.getElementById('next-btn');
    const finishBtn = document.getElementById('finish-btn');
    const tracker = document.getElementById('question-tracker');

    const totalQuestions = questions.length;
    
    // Check if we found any questions
    if (totalQuestions === 0 || !prevBtn || !nextBtn || !finishBtn || !tracker) {
        // Log an error if elements are missing
        console.error("Quiz initialization failed: Missing questions or navigation elements.");
        return;
    }

    let currentQuestionIndex = 0;

    // Function to show only the question at the given index
    function showQuestion(index) {
        questions.forEach((q, i) => {
            if (i === index) {
                q.classList.remove('hidden');
            } else {
                q.classList.add('hidden');
            }
        });
        currentQuestionIndex = index;
        updateButtons();
        updateTracker();
    }

    // Function to manage button visibility
    function updateButtons() {
        prevBtn.style.display = currentQuestionIndex > 0 ? 'inline-block' : 'none';
        nextBtn.style.display = currentQuestionIndex < totalQuestions - 1 ? 'inline-block' : 'none';
        finishBtn.style.display = currentQuestionIndex === totalQuestions - 1 ? 'inline-block' : 'none';
        
        if (currentQuestionIndex === totalQuestions - 1) {
             nextBtn.style.display = 'none';
        }
    }
    
    // Function to update the Question X of Y text
    function updateTracker() {
        tracker.textContent = `Question ${currentQuestionIndex + 1} of ${totalQuestions}`;
    }

    // Event Listeners
    nextBtn.addEventListener('click', () => {
        // Prevent going past the last question
        if (currentQuestionIndex < totalQuestions - 1) {
            showQuestion(currentQuestionIndex + 1);
        }
    });

    prevBtn.addEventListener('click', () => {
        // Prevent going before the first question
        if (currentQuestionIndex > 0) {
            showQuestion(currentQuestionIndex - 1);
        }
    });

    // Initialize the quiz view
    showQuestion(0);
});