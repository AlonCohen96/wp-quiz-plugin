<?php
/*
Plugin Name: WP Quiz Plugin
Description: A plugin for creating and managing custom WP quizzes.
Version: 1.0
Author: Alon Cohen
*/

require_once WP_CONTENT_DIR . '/wp-shared-utils/functions-xp.php';

/* ++++++++++++++++++++++++++++++++++++++++++++++ Plugin Activation ++++++++++++++++++++++++++++++++++++++++++++++ */
register_activation_hook(__FILE__, 'wp_quiz_plugin_create_tables');


/* ++++++++++++++++++++++++++++++++++++++++++++++ Table Creation ++++++++++++++++++++++++++++++++++++++++++++++ */
function wp_quiz_plugin_create_tables() {
    global $wpdb;

    // Table names
    $quizzes_table = $wpdb->prefix . 'quizzes';
    $quiz_questions_table = $wpdb->prefix . 'quiz_questions';
    $quiz_user_answers_table = $wpdb->prefix . 'quiz_user_answers';

    // Include the WordPress dbDelta function
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // SQL for the quizzes table
    $quizzes_sql = "CREATE TABLE $quizzes_table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );";

    // SQL for the quiz_questions table
    $quiz_questions_sql = "CREATE TABLE $quiz_questions_table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quiz_id INT NOT NULL,
        question_text TEXT NOT NULL,
        question_type ENUM('single_choice', 'multiple_choice') NOT NULL,
        options TEXT NOT NULL, -- Serialized array of options
        solution TEXT NOT NULL, -- Serialized array of correct answers
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (quiz_id) REFERENCES $quizzes_table(id) ON DELETE CASCADE
    );";

    // SQL for the quiz_user_answers table
    $quiz_user_answers_sql = "CREATE TABLE $quiz_user_answers_table (
        id INT AUTO_INCREMENT PRIMARY KEY,
        quiz_id INT NOT NULL,
        user_id INT NOT NULL, -- Only logged-in users
        question_id INT NOT NULL,
        user_answer TEXT NOT NULL, -- Serialized array of user's selected options
        correct BOOLEAN NOT NULL,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (quiz_id) REFERENCES $quizzes_table(id) ON DELETE CASCADE,
        FOREIGN KEY (question_id) REFERENCES $quiz_questions_table(id) ON DELETE CASCADE
    );";


    // Execute the SQL statements
    dbDelta($quizzes_sql);
    dbDelta($quiz_questions_sql);
    dbDelta($quiz_user_answers_sql);
}



/* ++++++++++++++++++++++++++++++++++++++++++++++ Admin Dashboard Setup ++++++++++++++++++++++++++++++++++++++++++++++ */

// Add a menu item in the WordPress admin dashboard
add_action('admin_menu', 'wp_quiz_plugin_admin_menu');

function wp_quiz_plugin_admin_menu() {
    add_menu_page(
        'WP Quiz Plugin',
        'Quizzes',
        'manage_options',
        'wp-quiz-plugin',
        'wp_quiz_plugin_main_page',
        'dashicons-welcome-learn-more'
    );

    add_submenu_page(
        'wp-quiz-plugin',
        'Add New Quiz',
        'Add New Quiz',
        'manage_options',
        'wp-quiz-plugin-add',
        'wp_quiz_plugin_add_quiz_page'
    );

    add_submenu_page(
        null, // Hidden page
        'Manage Quiz Questions',
        'Manage Quiz Questions',
        'manage_options',
        'wp-quiz-plugin-manage-questions',
        'wp_quiz_plugin_manage_questions_page'
    );

    add_submenu_page(
        'wp-quiz-plugin',
        'Manage Questions',
        'Manage Questions',
        'manage_options',
        'wp-quiz-plugin-manage-questions',
        'wp_quiz_plugin_manage_questions_page'
    );

    add_submenu_page(
        null,
        'Add/Edit Question',
        'Add/Edit Question',
        'manage_options',
        'wp-quiz-plugin-add-edit-question',
        'wp_quiz_plugin_add_edit_question_page'
    );
}


/* ++++++++++++++++++++++++++++++++++++++++++++++ Main page for managing Quizzes ++++++++++++++++++++++++++++++++++++++++++++++ */
function wp_quiz_plugin_main_page() {
    global $wpdb;
    $quizzes_table = $wpdb->prefix . 'quizzes';

    // Handle deletion
    if (isset($_GET['delete'])) {
        $delete_id = intval($_GET['delete']);
        $wpdb->delete($quizzes_table, ['id' => $delete_id]);
        echo "<div class='updated'><p>Quiz deleted successfully!</p></div>";
    }

    // Fetch all quizzes
    $quizzes = $wpdb->get_results("SELECT * FROM $quizzes_table");

    ?>
    <div class="wrap">
        <h1>Manage Quizzes</h1>
        <table class="widefat fixed">
            <thead>
            <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Description</th>
                <th>Shortcode</th>
                <th>Date Created</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($quizzes as $quiz): ?>
                <tr>
                    <td><?php echo $quiz->id; ?></td>
                    <td><?php echo esc_html($quiz->title); ?></td>
                    <td><?php echo esc_html($quiz->description); ?></td>
                    <td>[wp_quiz id="<?php echo $quiz->id; ?>"]</td>
                    <td><?php echo esc_html($quiz->created_at); ?></td>
                    <td>
                        <a href="<?php echo admin_url('admin.php?page=wp-quiz-plugin-add&quiz_id=' . $quiz->id); ?>">Edit</a> |
                        <a href="<?php echo admin_url('admin.php?page=wp-quiz-plugin&delete=' . $quiz->id); ?>" onclick="return confirm('Are you sure?');">Delete</a> |
                        <a href="<?php echo admin_url('admin.php?page=wp-quiz-plugin-manage-questions&quiz_id=' . $quiz->id); ?>">Manage Questions</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <a href="<?php echo admin_url('admin.php?page=wp-quiz-plugin-add'); ?>" class="button-primary">Add New Quiz</a>
    </div>
    <?php
}



/* ++++++++++++++++++++++++++++++++++++++++++++++ Page to add a new Quiz or edit an existing Quiz ++++++++++++++++++++++++++++++++++++++++++++++ */
function wp_quiz_plugin_add_quiz_page() {
    global $wpdb;
    $quizzes_table = $wpdb->prefix . 'quizzes';

    $quiz_id = isset($_GET['quiz_id']) ? intval($_GET['quiz_id']) : 0;
    $quiz = $quiz_id ? $wpdb->get_row("SELECT * FROM $quizzes_table WHERE id = $quiz_id") : null;

    // Handle form submission
    if ($_POST) {
        $title = sanitize_text_field($_POST['title']);
        $description = sanitize_text_field($_POST['description']);

        if ($quiz) {
            $wpdb->update($quizzes_table, compact('title', 'description'), ['id' => $quiz_id]);
            echo "<div class='updated'><p>Quiz updated successfully!</p></div>";
        } else {
            $wpdb->insert(
                $quizzes_table,
                [
                    'title'       => $title,
                    'description' => $description,
                    'created_at'  => current_time('mysql')
                ]
            );
            echo "<div class='updated'><p>Quiz added successfully!</p></div>";
        }
    }

    ?>
    <div class="wrap">
        <h1><?php echo $quiz ? 'Edit Quiz' : 'Add New Quiz'; ?></h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th><label for="title">Quiz Title</label></th>
                    <td><input type="text" name="title" id="title" value="<?php echo esc_attr(isset($quiz->title) ? $quiz->title : ''); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="description">Description</label></th>
                    <td><textarea name="description" id="description"><?php echo esc_textarea(isset($quiz->description) ? $quiz->description : ''); ?></textarea></td>
                </tr>
            </table>
            <p class="submit"><input type="submit" value="<?php echo $quiz ? 'Update Quiz' : 'Add Quiz'; ?>" class="button-primary"></p>
        </form>
    </div>
    <?php
}



/* ++++++++++++++++++++++++++++++++++++++++++++++ Page to manage questions of a Quiz ++++++++++++++++++++++++++++++++++++++++++++++ */
function wp_quiz_plugin_manage_questions_page() {
    global $wpdb;

    $quiz_id = isset($_GET['quiz_id']) ? intval($_GET['quiz_id']) : 0;
    $quiz_table = $wpdb->prefix . 'quizzes';
    $questions_table = $wpdb->prefix . 'quiz_questions';

    // Fetch quiz details
    $quiz = $wpdb->get_row($wpdb->prepare("SELECT * FROM $quiz_table WHERE id = %d", $quiz_id));
    if (!$quiz) {
        echo "<div class='notice notice-error'><p>Invalid Quiz ID.</p></div>";
        return;
    }

    // Handle deletion of a question
    if (isset($_GET['delete_question_id'])) {
        $question_id = intval($_GET['delete_question_id']);
        $wpdb->delete($questions_table, ['id' => $question_id]);
        echo "<div class='updated'><p>Question deleted successfully!</p></div>";
    }

    // Fetch all questions for the quiz
    $questions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $questions_table WHERE quiz_id = %d", $quiz_id));

    ?>
    <div class="wrap">
        <h1>Manage Questions for Quiz: <?php echo esc_html($quiz->title); ?></h1>

        <table class="widefat fixed" cellspacing="0">
            <thead>
            <tr>
                <th>ID</th>
                <th>Question Text</th>
                <th>Type</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($questions): ?>
                <?php foreach ($questions as $question): ?>
                    <tr>
                        <td><?php echo $question->id; ?></td>
                        <td><?php echo esc_html($question->question_text); ?></td>
                        <td><?php echo esc_html($question->question_type); ?></td>
                        <td>
                            <a href="<?php echo admin_url("admin.php?page=wp-quiz-plugin-add-edit-question&quiz_id=$quiz_id&question_id={$question->id}"); ?>">Edit</a> |
                            <a href="<?php echo admin_url("admin.php?page=wp-quiz-plugin-manage-questions&quiz_id=$quiz_id&delete_question_id={$question->id}"); ?>" onclick="return confirm('Are you sure you want to delete this question?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="4">No questions found for this quiz.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>

        <a href="<?php echo admin_url("admin.php?page=wp-quiz-plugin-add-edit-question&quiz_id=$quiz_id"); ?>" class="button-primary">Add New Question</a>
    </div>
    <?php
}



/* ++++++++++++++++++++++++++++++++++++++++++++++ Page to add or edit a Question ++++++++++++++++++++++++++++++++++++++++++++++ */
function wp_quiz_plugin_add_edit_question_page() {
    global $wpdb;

    $quiz_id = isset($_GET['quiz_id']) ? intval($_GET['quiz_id']) : 0;
    $question_id = isset($_GET['question_id']) ? intval($_GET['question_id']) : 0;

    $questions_table = $wpdb->prefix . 'quiz_questions';

    // Fetch quiz details
    $quiz = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}quizzes WHERE id = %d", $quiz_id));
    if (!$quiz) {
        echo "<div class='notice notice-error'><p>Invalid Quiz ID.</p></div>";
        return;
    }

    // Fetch question details if editing
    $question = $question_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $questions_table WHERE id = %d", $question_id)) : null;

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $question_text = sanitize_text_field($_POST['question_text']);
        $question_type = sanitize_text_field($_POST['question_type']);
        $options = isset($_POST['options']) ? maybe_serialize(array_map('sanitize_text_field', explode(",", $_POST['options']))) : '';

        // Handle solution storage based on question type
        if ($question_type === 'single_choice') {
            $solution = isset($_POST['solution']) ? sanitize_text_field($_POST['solution']) : '';
        } else {
            $solution = isset($_POST['solution']) ? maybe_serialize(array_map('sanitize_text_field', explode(",", $_POST['solution']))) : '';
        }

        if ($question) {
            // Update existing question
            $wpdb->update(
                $questions_table,
                [
                    'question_text' => $question_text,
                    'question_type' => $question_type,
                    'options' => $options,
                    'solution' => $solution,
                ],
                ['id' => $question_id]
            );
            echo "<div class='updated'><p>Question updated successfully!</p></div>";
        } else {
            // Insert new question
            $wpdb->insert(
                $questions_table,
                [
                    'quiz_id' => $quiz_id,
                    'question_text' => $question_text,
                    'question_type' => $question_type,
                    'options' => $options,
                    'solution' => $solution,
                    'created_at' => current_time('mysql'),
                ]
            );
            echo "<div class='updated'><p>Question added successfully!</p></div>";
        }
    }

    // Prepare values for editing
    $question_text = $question ? $question->question_text : '';
    $question_type = $question ? $question->question_type : '';
    $options = $question ? maybe_unserialize($question->options) : '';
    $solution = $question ? $question->solution : ''; // Adjusted for single-choice string

    ?>
    <div class="wrap">
        <h1><?php echo $question ? 'Edit Question' : 'Add New Question'; ?></h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th><label for="question_text">Question Text</label></th>
                    <td><input type="text" id="question_text" name="question_text" class="regular-text" value="<?php echo esc_attr($question_text); ?>" required></td>
                </tr>
                <tr>
                    <th><label for="question_type">Question Type</label></th>
                    <td>
                        <select id="question_type" name="question_type">
                            <option value="single_choice" <?php selected($question_type, 'single_choice'); ?>>Single Choice</option>
                            <option value="multiple_choice" <?php selected($question_type, 'multiple_choice'); ?>>Multiple Choice</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="options">Options (comma-separated)</label></th>
                    <td><input type="text" id="options" name="options" class="regular-text" value="<?php echo esc_attr(implode(",", (array)$options)); ?>"></td>
                </tr>
                <tr>
                    <th><label for="solution">Correct Answer(s) <?php echo $question_type === 'single_choice' ? '(single)' : '(comma-separated)'; ?></label></th>
                    <td><input type="text" id="solution" name="solution" class="regular-text" value="<?php echo esc_attr($solution); ?>"></td>
                </tr>
            </table>
            <p class="submit">
                <input type="submit" value="Save Question" class="button-primary">
            </p>
        </form>
    </div>
    <?php
}


/* ++++++++++++++++++++++++++++++++++++++++++++++ Shortcode function to display the Quiz ++++++++++++++++++++++++++++++++++++++++++++++ */
add_shortcode('wp_quiz', 'wp_quiz_plugin_display_quiz');

function wp_quiz_plugin_display_quiz($atts) {
    global $wpdb;

    // Extract shortcode attributes
    $atts = shortcode_atts(array(
        'id' => 0, // Default quiz ID
    ), $atts);

    $quiz_id = intval($atts['id']);
    if (!$quiz_id) {
        return "<p>No quiz specified. Please provide a quiz ID.</p>";
    }

    // Fetch the quiz data
    $quizzes_table = $wpdb->prefix . 'quizzes';
    $quiz_questions_table = $wpdb->prefix . 'quiz_questions';

    $quiz = $wpdb->get_row($wpdb->prepare("SELECT * FROM $quizzes_table WHERE id = %d", $quiz_id));
    if (!$quiz) {
        return "<p>Quiz not found.</p>";
    }

    // Fetch questions for the quiz
    $questions = $wpdb->get_results($wpdb->prepare("SELECT * FROM $quiz_questions_table WHERE quiz_id = %d", $quiz_id));
    if (empty($questions)) {
        return "<p>This quiz has no questions yet.</p>";
    }

    // Generate a unique result container ID
    $result_div_id = 'quiz_result_' . $quiz_id;

    // Start output buffering for dynamic rendering
    ob_start();
    ?>
    <div class="wp-quiz-container">
        <h2><?php echo esc_html($quiz->title); ?></h2>
        <p><?php echo esc_html($quiz->description); ?></p>
        <form method="post" action="" class="wp-quiz-form" data-result-id="<?php echo esc_attr($result_div_id); ?>">
            <?php foreach ($questions as $index => $question): ?>
                <div class="quiz-question" data-question-id="<?php echo esc_attr($question->id); ?>">
                    <h3><?php echo esc_html(($index + 1) . '. ' . $question->question_text); ?></h3>
                    <?php
                    $options = maybe_unserialize($question->options);
                    if ($question->question_type === 'single_choice'):
                        ?>
                        <?php foreach ($options as $option): ?>
                        <label>
                            <input type="radio" name="answers[<?php echo $question->id; ?>]" value="<?php echo esc_attr($option); ?>" required>
                            <?php echo esc_html($option); ?>
                        </label><br>
                    <?php endforeach; ?>
                    <?php elseif ($question->question_type === 'multiple_choice'): ?>
                        <?php foreach ($options as $option): ?>
                            <label>
                                <input type="checkbox" name="answers[<?php echo $question->id; ?>][]" value="<?php echo esc_attr($option); ?>">
                                <?php echo esc_html($option); ?>
                            </label><br>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php wp_nonce_field('wp_quiz_nonce', 'quiz_nonce'); ?>
            <button type="submit" class="quiz-submit-button button">Submit Quiz</button>
        </form>
        <div
                id="<?php echo esc_attr($result_div_id); ?>"
                class="quiz-result"
                style="display:none;"
        >
        </div>
        <button
                type="button"
                class="retake-button"
                id="<?php echo esc_attr($result_div_id); ?>_retake"
                style="display:none;"
        >
            Retake Quiz
        </button>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const forms = document.querySelectorAll('.wp-quiz-form');
            forms.forEach((form) => {
                const resultDivId = form.getAttribute('data-result-id');
                const resultDiv = document.getElementById(resultDivId);
                const retakeButton = document.getElementById(`${resultDivId}_retake`);

                form.addEventListener('submit', function (event) {
                    event.preventDefault();

                    // Collect form data
                    const formData = new FormData(form);
                    formData.append('action', 'wp_quiz_submit_answers'); // Add action for AJAX handler
                    formData.append('quiz_id', '<?php echo $quiz_id; ?>'); // Add quiz ID

                    // Perform AJAX request
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                        method: 'POST',
                        body: formData,
                    })
                        .then((response) => {
                            if (!response.ok) {
                                throw new Error('Network response was not ok');
                            }
                            return response.json();
                        })
                        .then((data) => {
                            if (data.success) {
                                const scoreHeading = document.createElement('h3');
                                scoreHeading.textContent = `Your Score: ${data.data.score}/${data.data.total}`;
                                resultDiv.innerHTML = ''; // Clear previous content
                                resultDiv.appendChild(scoreHeading);
                                resultDiv.style.display = 'block';
                                retakeButton.style.display = 'inline-block';

                                // Display feedback for each question
                                const feedback = data.data.feedback;
                                feedback.forEach((item) => {
                                    const questionElement = document.querySelector(`[data-question-id="${item.question_id}"]`);
                                    if (questionElement) {
                                        const userAnswers = Array.isArray(item.user_answer) ? item.user_answer : [item.user_answer];
                                        const correctAnswers = Array.isArray(item.correct_answer) ? item.correct_answer : [item.correct_answer];

                                        questionElement.querySelectorAll('input').forEach((input) => {
                                            const label = input.closest('label');

                                            // Clear previous feedback icons (if any)
                                            const oldIcons = label.querySelectorAll('.feedback-icon, .correct-icon');
                                            oldIcons.forEach(icon => icon.remove());

                                            const value = input.value;

                                            // Preserve user's selection
                                            if (userAnswers.includes(value)) {
                                                input.checked = true;

                                                // Mark user-selected answers as correct/incorrect
                                                if (correctAnswers.includes(value)) {
                                                    const correctIcon = document.createElement('span');
                                                    correctIcon.textContent = ' ✅';
                                                    correctIcon.classList.add('feedback-icon');
                                                    label.appendChild(correctIcon);
                                                } else {
                                                    const incorrectIcon = document.createElement('span');
                                                    incorrectIcon.textContent = ' ❌';
                                                    incorrectIcon.classList.add('feedback-icon');
                                                    label.appendChild(incorrectIcon);
                                                }
                                            }

                                            // Highlight correct answers if not selected
                                            if (correctAnswers.includes(value) && !userAnswers.includes(value)) {
                                                const correctIcon = document.createElement('span');
                                                correctIcon.textContent = ' ☑';
                                                correctIcon.classList.add('correct-icon');
                                                label.appendChild(correctIcon);
                                            }
                                        });
                                    }
                                });
                            } else {
                                resultDiv.innerHTML = `<p>${data.data.message}</p>`;
                                resultDiv.style.display = 'block';
                            }
                        })
                        .catch((error) => {
                            console.error('Error:', error);
                            resultDiv.innerHTML = `<p>An error occurred. Please try again later.</p>`;
                            resultDiv.style.display = 'block';
                        });
                });

                // Add functionality for retake button
                retakeButton.addEventListener('click', () => {
                    // Uncheck all inputs
                    form.querySelectorAll('input').forEach(input => {
                        input.checked = false;
                    });

                    // Remove feedback icons
                    form.querySelectorAll('.feedback-icon, .correct-icon').forEach(icon => {
                        icon.remove();
                    });

                    // Hide result div and retake button
                    resultDiv.style.display = 'none';
                    retakeButton.style.display = 'none';
                });
            });
        });
    </script>

    <?php
    return ob_get_clean();
}



/* ++++++++++++++++++++++++++++++++++++++++++++++ Handle Quiz submission ++++++++++++++++++++++++++++++++++++++++++++++ */
add_action('wp_ajax_wp_quiz_submit_answers', 'wp_quiz_plugin_handle_ajax');

function wp_quiz_plugin_handle_ajax() {
    if (!isset($_POST['quiz_nonce']) || !wp_verify_nonce($_POST['quiz_nonce'], 'wp_quiz_nonce')) {
        wp_send_json_error(['message' => 'Invalid nonce.']);
    }

    global $wpdb;
    $quiz_id = intval($_POST['quiz_id']);
    $questions_table = $wpdb->prefix . 'quiz_questions';
    $user_answers_table = $wpdb->prefix . 'quiz_user_answers';
    $answers = $_POST;

    unset($answers['action'], $answers['quiz_id'], $answers['quiz_nonce']);

    $user_id = get_current_user_id();

    // Fetch all questions for the quiz
    $questions = $wpdb->get_results($wpdb->prepare(
        "SELECT id, question_type, solution, question_text FROM $questions_table WHERE quiz_id = %d",
        $quiz_id
    ));

    if (!$questions) {
        wp_send_json_error(['message' => 'Quiz not found.']);
    }

    $score = 0;
    $total = count($questions);
    $feedback = []; // Store feedback for each question

    // Check if the user has already submitted answers for this quiz
    $already_submitted = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $user_answers_table WHERE quiz_id = %d AND user_id = %d",
        $quiz_id,
        $user_id
    ));

    // Loop through each question to evaluate answers
    foreach ($questions as $question) {
        $question_id = $question->id;
        $correct_answer = maybe_unserialize($question->solution);
        $user_answer = $answers['answers'][$question_id] ?? null;
        $is_correct = false;

        // Evaluate the answer based on the question type
        if ($question->question_type === 'single_choice') {
            $is_correct = ($user_answer === $correct_answer);
        } elseif ($question->question_type === 'multiple_choice') {
            $is_correct = (
                is_array($user_answer) &&
                empty(array_diff($user_answer, $correct_answer)) &&
                empty(array_diff($correct_answer, $user_answer))
            );
        }

        // Increment score if the answer is correct
        if ($is_correct) {
            $score++;
        }

        // Feedback for each question
        $feedback[] = [
            'question_id' => $question_id,
            'question_text' => $question->question_text,
            'user_answer' => $user_answer,
            'correct_answer' => $correct_answer,
            'is_correct' => $is_correct,
        ];

        // Only save answers to the database if it's the first submission
        if ($already_submitted === 0) {
            $wpdb->insert(
                $user_answers_table,
                [
                    'quiz_id'     => $quiz_id,
                    'user_id'     => $user_id,
                    'question_id' => $question_id,
                    'user_answer' => maybe_serialize($user_answer),
                    'correct'     => $is_correct ? 1 : 0,
                ],
                ['%d', '%d', '%d', '%s', '%d']
            );
        }
    }

    // Only reward XP if it's the first submission
    if ($already_submitted === 0) {
        add_xp($user_id, 200);
    }

    // Return the quiz result with feedback
    wp_send_json_success([
        'score' => $score,
        'total' => $total,
        'feedback' => $feedback, // Add feedback data
    ]);
}



