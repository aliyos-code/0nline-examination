<?php
/**
 * Student: prepare and start an examination attempt.
 *
 * GET  -> show the examination instructions with a "Begin" confirmation form.
 * POST -> create the attempt (one per student per examination), randomise the
 *         questions and answer-option order, then redirect to take_exam.php.
 */
require_once __DIR__ . '/../includes/auth.php';
require_student();

$pdo       = db();
auto_close_expired_exams($pdo);
$studentId = (int) current_user()['id'];

// Resolve the examination id from GET (instructions view) or POST (begin).
$examId = (int) (input('exam') !== '' ? input('exam') : input('examination_id'));

$stmt = $pdo->prepare('SELECT * FROM examinations WHERE id = ?');
$stmt->execute([$examId]);
$exam = $stmt->fetch();

if (!$exam || !student_can_see_exam($pdo, (int) $exam['id'], $studentId)) {
    set_flash('danger', 'That examination is not available.');
    redirect('student/examinations.php');
}
$displayStatus = exam_display_status($exam);

// How many questions are actually available?
$stmt = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE examination_id = ?');
$stmt->execute([$examId]);
$available = (int) $stmt->fetchColumn();

if ($available < 1) {
    set_flash('warning', 'This examination has no questions yet.');
    redirect('student/examinations.php');
}

// Any attempt still in progress? Resume it - the schedule window does not stop
// a sitting that has already begun; its own deadline governs.
$stmt = $pdo->prepare(
    "SELECT * FROM exam_attempts
     WHERE student_id = ? AND examination_id = ? AND status = 'in_progress'
     ORDER BY id DESC LIMIT 1"
);
$stmt->execute([$studentId, $examId]);
$inProgress = $stmt->fetch();

if ($inProgress) {
    $_SESSION['attempt_id'] = (int) $inProgress['id'];

    // If the deadline has already passed, finalise immediately.
    if (strtotime($inProgress['deadline']) <= time()) {
        finalize_attempt((int) $inProgress['id']);
        unset($_SESSION['attempt_id']);
        set_flash('warning', 'Your examination time had already expired, so it was submitted automatically.');
        redirect('student/results.php?id=' . (int) $inProgress['id']);
    }
    // Closed examinations cannot be accessed, even to resume.
    if ($displayStatus === 'CLOSED') {
        finalize_attempt((int) $inProgress['id']);
        unset($_SESSION['attempt_id']);
        set_flash('warning', 'This examination is CLOSED and has been submitted automatically.');
        redirect('student/results.php?id=' . (int) $inProgress['id']);
    }
    redirect('student/take_exam.php');
}

// Backend visibility guard (not just a hidden button): only OPEN exams
// may be started. NOT YET OPEN and CLOSED are blocked on the server.
if ($displayStatus === 'NOT YET OPEN') {
    set_flash('warning', 'This examination is NOT YET OPEN. Please wait until the administrator opens it.');
    redirect('student/examinations.php');
}
if ($displayStatus === 'CLOSED') {
    set_flash('warning', 'This examination is CLOSED.');
    redirect('student/examinations.php');
}

// Attempts used vs. the allowance configured on the examination.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM exam_attempts WHERE student_id = ? AND examination_id = ?');
$stmt->execute([$studentId, $examId]);
$attemptsUsed = (int) $stmt->fetchColumn();
$maxAttempts  = max(1, (int) $exam['max_attempts']);

if ($attemptsUsed >= $maxAttempts) {
    // No attempts left - send the student to their most recent result, if any.
    $stmt = $pdo->prepare(
        "SELECT id FROM exam_attempts
         WHERE student_id = ? AND examination_id = ? AND status = 'completed'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$studentId, $examId]);
    $lastId = (int) $stmt->fetchColumn();

    set_flash('info', 'You have used all your attempts for this examination.');
    redirect($lastId > 0 ? 'student/results.php?id=' . $lastId : 'student/history.php');
}

// Scheduling window: a new attempt may only begin while the examination is open.
$window = exam_window_state($exam);
if ($window === 'upcoming') {
    set_flash('warning', 'This examination opens on ' . date('d M Y H:i', strtotime($exam['available_from'])) . '.');
    redirect('student/examinations.php');
}
if ($window === 'closed') {
    set_flash('warning', 'This examination closed on ' . date('d M Y H:i', strtotime($exam['available_until'])) . '.');
    redirect('student/examinations.php');
}

// ---------------------------------------------------------------------------
// Begin the attempt (POST only)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        set_flash('danger', 'Your session expired. Please try again.');
        redirect('student/examinations.php');
    }

    // Determine how many questions to serve.
    $limit = (int) $exam['total_questions'];
    if ($limit < 1 || $limit > $available) {
        $limit = $available;
    }

    // Randomised question selection.
    $stmt = $pdo->prepare("SELECT id FROM questions WHERE examination_id = ? ORDER BY RAND() LIMIT {$limit}");
    $stmt->execute([$examId]);
    $questionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$questionIds) {
        set_flash('danger', 'This examination has no questions yet.');
        redirect('student/examinations.php');
    }

    $now      = time();
    $start    = date('Y-m-d H:i:s', $now);
    $deadline = date('Y-m-d H:i:s', $now + ((int) $exam['duration_minutes'] * 60));

    $pdo->beginTransaction();
    try {
        $insertAttempt = $pdo->prepare(
            'INSERT INTO exam_attempts
                (student_id, examination_id, start_time, deadline, total_questions, status, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?, \'in_progress\', ?, ?)'
        );
        $insertAttempt->execute([$studentId, $examId, $start, $deadline, count($questionIds), client_ip(), client_user_agent()]);
        $attemptId = (int) $pdo->lastInsertId();

        $insertQ = $pdo->prepare(
            'INSERT INTO attempt_questions (attempt_id, question_id, display_order, option_order)
             VALUES (?, ?, ?, ?)'
        );

        $order = 1;
        foreach ($questionIds as $qid) {
            // Randomise the display order of the four options.
            $options = ['A', 'B', 'C', 'D'];
            shuffle($options);
            $insertQ->execute([$attemptId, (int) $qid, $order++, implode(',', $options)]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        set_flash('danger', 'We could not start the examination. Please try again.');
        redirect('student/examinations.php');
    }

    $_SESSION['attempt_id'] = $attemptId;
    set_flash('success', 'Good luck! Your examination has started.');
    redirect('student/take_exam.php');
}

// ---------------------------------------------------------------------------
// Instructions view (GET)
// ---------------------------------------------------------------------------
$page_title = 'Examination Instructions';
require __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Examination Instructions</div>
            <div class="card-body">
                <h1 class="h4"><?php echo e($exam['title']); ?></h1>
                <p class="text-muted"><?php echo e($exam['course_code'] . ' - ' . $exam['course_name']); ?></p>

                <div class="row text-center my-4">
                    <div class="col">
                        <div class="h4 mb-0"><?php echo (int) $exam['duration_minutes']; ?></div>
                        <div class="small text-muted">Minutes</div>
                    </div>
                    <div class="col">
                        <div class="h4 mb-0"><?php echo $available; ?></div>
                        <div class="small text-muted">Questions</div>
                    </div>
                    <div class="col">
                        <div class="h4 mb-0"><?php echo (int) $exam['pass_mark']; ?>%</div>
                        <div class="small text-muted">Pass mark</div>
                    </div>
                </div>

                <?php if (trim((string) $exam['instructions']) !== ''): ?>
                    <div class="alert alert-light border">
                        <?php echo nl2br(e($exam['instructions'])); ?>
                    </div>
                <?php endif; ?>

                <ul class="text-muted small">
                    <li>The timer starts as soon as you begin and counts down on the server.</li>
                    <li>You may move between questions and change answers before submitting.</li>
                    <li>The examination submits automatically when the time runs out.</li>
                    <?php if ($maxAttempts > 1): ?>
                        <li>You may attempt this examination up to <?php echo (int) $maxAttempts; ?> times
                            (<?php echo max(0, $maxAttempts - $attemptsUsed); ?> remaining).</li>
                    <?php else: ?>
                        <li>You can attempt this examination once.</li>
                    <?php endif; ?>
                </ul>

                <form method="post" action="<?php echo url('student/start_exam.php'); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="examination_id" value="<?php echo (int) $exam['id']; ?>">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-play-fill me-1"></i>Begin Examination
                        </button>
                        <a href="<?php echo url('student/examinations.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
