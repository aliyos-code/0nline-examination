<?php
/**
 * Student: the examination-taking interface.
 *
 * All questions for the attempt are rendered on a single page; JavaScript
 * shows them one at a time and drives the timer. Answers are also saved
 * progressively via save_answer.php so that a server-side auto-submit has the
 * student's data even if the browser is closed.
 */
require_once __DIR__ . '/../includes/auth.php';
require_student();

$pdo       = db();
auto_close_expired_exams($pdo);
$studentId = (int) current_user()['id'];

$attemptId = isset($_SESSION['attempt_id']) ? (int) $_SESSION['attempt_id'] : 0;

$stmt = $pdo->prepare(
    'SELECT a.*, e.title AS exam_title, e.course_code, e.course_name, e.pass_mark, e.instructions, e.status AS exam_status, e.available_from, e.available_until,
            s.full_name AS student_name, s.reg_number
     FROM exam_attempts a
     JOIN examinations e ON e.id = a.examination_id
     JOIN students s ON s.id = a.student_id
     WHERE a.id = ? AND a.student_id = ?'
);
$stmt->execute([$attemptId, $studentId]);
$attempt = $stmt->fetch();

if (!$attempt) {
    unset($_SESSION['attempt_id']);
    set_flash('warning', 'No active examination was found. Please start one from the list.');
    redirect('student/examinations.php');
}

if ($attempt['status'] === 'completed') {
    unset($_SESSION['attempt_id']);
    redirect('student/results.php?id=' . (int) $attempt['id']);
}

// Backend guard: a CLOSED examination cannot be accessed.
if (exam_display_status($attempt) === 'CLOSED') {
    finalize_attempt((int) $attempt['id']);
    unset($_SESSION['attempt_id']);
    set_flash('warning', 'This examination is CLOSED and has been submitted automatically.');
    redirect('student/results.php?id=' . (int) $attempt['id']);
}

// Server-side timing enforcement: never trust the browser clock.
$remaining = strtotime($attempt['deadline']) - time();
if ($remaining <= 0) {
    finalize_attempt((int) $attempt['id']);
    unset($_SESSION['attempt_id']);
    set_flash('warning', 'Your examination time expired and it was submitted automatically.');
    redirect('student/results.php?id=' . (int) $attempt['id']);
}

// Load the assigned questions with the student's saved answers.
$stmt = $pdo->prepare(
    'SELECT aq.question_id, aq.display_order, aq.option_order,
            q.question_text, q.option_a, q.option_b, q.option_c, q.option_d,
            sa.selected_option
     FROM attempt_questions aq
     JOIN questions q ON q.id = aq.question_id
     LEFT JOIN student_answers sa
            ON sa.attempt_id = aq.attempt_id AND sa.question_id = aq.question_id
     WHERE aq.attempt_id = ?
     ORDER BY aq.display_order ASC'
);
$stmt->execute([$attemptId]);
$questions = $stmt->fetchAll();

if (!$questions) {
    set_flash('danger', 'This examination has no questions.');
    redirect('student/examinations.php');
}

$total      = count($questions);
$optionText = ['A' => 'option_a', 'B' => 'option_b', 'C' => 'option_c', 'D' => 'option_d'];
$positionLabels = ['A', 'B', 'C', 'D'];
$csrf = csrf_token();

$page_title = $attempt['exam_title'];
require __DIR__ . '/../includes/header.php';
?>
<div id="examApp"
     data-total="<?php echo $total; ?>"
     data-attempt-id="<?php echo (int) $attempt['id']; ?>"
     data-remaining="<?php echo (int) $remaining; ?>"
     data-csrf="<?php echo e($csrf); ?>"
     data-save-url="<?php echo url('student/save_answer.php'); ?>"
     data-log-url="<?php echo url('student/log_event.php'); ?>">

    <div class="card shadow-sm mb-3 sticky-top" style="top: 0; z-index: 1020;">
        <div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <div class="fw-semibold"><?php echo e($attempt['exam_title']); ?></div>
                    <div class="small text-muted"><?php echo e($attempt['course_code'] . ' - ' . $attempt['course_name']); ?></div>
                    <div class="small text-muted">Student: <?php echo e($attempt['student_name']); ?> (<?php echo e($attempt['reg_number']); ?>) &middot; <?php echo $total; ?> questions</div>
                    <?php if (trim((string) $attempt['instructions']) !== ''): ?>
                        <div class="small text-muted mt-1"><strong>Instructions:</strong> <?php echo nl2br(e($attempt['instructions'])); ?></div>
                    <?php endif; ?>
                </div>
                <div class="text-end">
                    <div class="small text-muted">Time remaining</div>
                    <span id="timer" class="badge bg-dark timer-badge">--:--</span>
                </div>
            </div>
            <div class="progress mt-2" style="height: 6px;">
                <div id="examProgress" class="progress-bar" role="progressbar" style="width:0%"></div>
            </div>
            <div class="small text-muted mt-1" id="examProgressText"></div>
        </div>
    </div>

    <form id="examForm" method="post" action="<?php echo url('student/submit_exam.php'); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="attempt_id" value="<?php echo (int) $attempt['id']; ?>">

        <div class="row g-4">
            <div class="col-lg-8">
                <?php $index = 0; foreach ($questions as $q): ?>
                    <div class="question-box p-4 mb-3 exam-question <?php echo $index === 0 ? '' : 'd-none'; ?>"
                         data-question-id="<?php echo (int) $q['question_id']; ?>"
                         data-index="<?php echo $index; ?>">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="badge bg-primary">Question <?php echo $index + 1; ?> of <?php echo $total; ?></span>
                        </div>
                        <p class="fs-5"><?php echo e($q['question_text']); ?></p>

                        <?php
                        // Only the options that actually exist for this question
                        // (a True/False question has just two).
                        $available = [];
                        foreach ($optionText as $letterKey => $column) {
                            if (trim((string) $q[$column]) !== '') {
                                $available[] = $letterKey;
                            }
                        }
                        $order = array_values(array_filter(
                            array_map('trim', explode(',', (string) $q['option_order'])),
                            function ($letter) use ($available) {
                                return in_array($letter, $available, true);
                            }
                        ));
                        if (count($order) !== count($available)) {
                            $order = $available;
                        }
                        foreach ($order as $pos => $original):
                            $letter = $positionLabels[$pos];
                            $checked = ($q['selected_option'] === $original);
                        ?>
                            <label class="option-label <?php echo $checked ? 'selected' : ''; ?>">
                                <input type="radio"
                                       name="answers[<?php echo (int) $q['question_id']; ?>]"
                                       value="<?php echo e($original); ?>"
                                       <?php echo $checked ? 'checked' : ''; ?>>
                                <strong><?php echo $letter; ?>.</strong>
                                <?php echo e($q[$optionText[$original]]); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php $index++; endforeach; ?>

                <div class="d-flex justify-content-between">
                    <button type="button" id="btnPrev" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Previous
                    </button>
                    <div>
                        <button type="button" id="btnNext" class="btn btn-outline-primary">
                            Next<i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-white fw-semibold">Question Navigator</div>
                    <div class="card-body question-nav">
                        <?php for ($i = 0; $i < $total; $i++): ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm qnav-btn" data-index="<?php echo $i; ?>">
                                <?php echo $i + 1; ?>
                            </button>
                        <?php endfor; ?>
                    </div>
                </div>

                <button type="submit" id="btnSubmit" class="btn btn-success w-100 py-2">
                    <i class="bi bi-send-check me-1"></i>Submit Examination
                </button>
                <p class="small text-muted mt-2 mb-0">
                    Legend: green = answered, outlined = current question.
                </p>
            </div>
        </div>
    </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
