<?php
/**
 * Student: detailed result for a single completed examination attempt.
 */
require_once __DIR__ . '/../includes/auth.php';
require_student();

$pdo       = db();
$studentId = (int) current_user()['id'];
$attemptId = (int) input('id');

$stmt = $pdo->prepare(
    'SELECT a.*, e.title AS exam_title, e.course_code, e.course_name, e.pass_mark
     FROM exam_attempts a
     JOIN examinations e ON e.id = a.examination_id
     WHERE a.id = ? AND a.student_id = ?'
);
$stmt->execute([$attemptId, $studentId]);
$attempt = $stmt->fetch();

if (!$attempt || $attempt['status'] !== 'completed') {
    set_flash('danger', 'That result is not available.');
    redirect('student/history.php');
}

$page_title = 'Examination Result';
require __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h1 class="h3 mb-0">Examination Result</h1>
            <div class="d-flex gap-2">
                <a href="<?php echo url('print/result.php?id=' . (int) $attemptId); ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-printer me-1"></i>Print / Save PDF
                </a>
                <a href="<?php echo url('student/history.php'); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-clock-history me-1"></i>History
                </a>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5 mb-1"><?php echo e($attempt['exam_title']); ?></h2>
                <p class="text-muted mb-3"><?php echo e($attempt['course_code'] . ' - ' . $attempt['course_name']); ?></p>

                <div class="text-center py-3">
                    <?php if ($attempt['pass_fail'] === 'pass'): ?>
                        <i class="bi bi-patch-check-fill text-success display-3"></i>
                        <div class="h3 mt-2 text-success">PASS</div>
                    <?php else: ?>
                        <i class="bi bi-x-octagon-fill text-danger display-3"></i>
                        <div class="h3 mt-2 text-danger">FAIL</div>
                    <?php endif; ?>
                    <div class="display-5 fw-bold"><?php echo e($attempt['percentage']); ?>%</div>
                    <div class="text-muted">Pass mark: <?php echo (int) $attempt['pass_mark']; ?>%</div>
                </div>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="card text-center shadow-sm"><div class="card-body">
                    <div class="h4 mb-0"><?php echo (int) $attempt['total_questions']; ?></div>
                    <div class="small text-muted">Total Questions</div>
                </div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center shadow-sm"><div class="card-body">
                    <div class="h4 mb-0"><?php echo (int) $attempt['score']; ?>/<?php echo (int) $attempt['total_questions']; ?></div>
                    <div class="small text-muted">Score</div>
                </div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center shadow-sm"><div class="card-body">
                    <div class="h4 mb-0 text-success"><?php echo (int) $attempt['correct_answers']; ?></div>
                    <div class="small text-muted">Correct</div>
                </div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center shadow-sm"><div class="card-body">
                    <div class="h4 mb-0 text-danger"><?php echo (int) $attempt['wrong_answers']; ?></div>
                    <div class="small text-muted">Wrong</div>
                </div></div>
            </div>
        </div>

        <div class="card shadow-sm mt-4">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Examination date</dt><dd class="col-sm-8"><?php echo e($attempt['end_time']); ?></dd>
                    <dt class="col-sm-4">Started</dt><dd class="col-sm-8"><?php echo e($attempt['start_time']); ?></dd>
                    <dt class="col-sm-4">Course</dt><dd class="col-sm-8 mb-0"><?php echo e($attempt['course_name'] . ' (' . $attempt['course_code'] . ')'); ?></dd>
                </dl>
            </div>
        </div>

        <div class="text-center mt-4">
            <a href="<?php echo url('student/dashboard.php'); ?>" class="btn btn-primary">
                <i class="bi bi-speedometer2 me-1"></i>Back to Dashboard
            </a>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
