<?php
/**
 * Student: list of available (active) examinations with instructions.
 */
require_once __DIR__ . '/../includes/auth.php';
require_student();

$pdo       = db();
auto_close_expired_exams($pdo);
$studentId = (int) current_user()['id'];

$stmt = $pdo->prepare(
    "SELECT e.*,
            (SELECT COUNT(*) FROM questions q WHERE q.examination_id = e.id) AS question_count,
            (SELECT COUNT(*) FROM exam_attempts a
              WHERE a.examination_id = e.id AND a.student_id = ?) AS attempts_used,
            (SELECT a.id FROM exam_attempts a
              WHERE a.examination_id = e.id AND a.student_id = ? AND a.status = 'in_progress'
              ORDER BY a.id DESC LIMIT 1) AS active_attempt_id,
            (SELECT a.id FROM exam_attempts a
              WHERE a.examination_id = e.id AND a.student_id = ? AND a.status = 'completed'
              ORDER BY a.id DESC LIMIT 1) AS last_completed_id
     FROM examinations e
     ORDER BY e.created_at DESC"
);
$stmt->execute([$studentId, $studentId, $studentId]);
$examinations = array_values(array_filter($stmt->fetchAll(), function ($e) use ($pdo, $studentId) {
    return student_can_see_exam($pdo, (int) $e['id'], $studentId);
}));

$examSearch = input('search');
if ($examSearch !== '') {
    $examinations = array_values(array_filter($examinations, function ($e) use ($examSearch) {
        return stripos($e['title'] . ' ' . $e['course_code'] . ' ' . $e['course_name'], $examSearch) !== false;
    }));
}

$page_title = 'Available Examinations';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-4">Available Examinations</h1>
<form class="row g-2 mb-3" method="get" action="<?php echo url('student/examinations.php'); ?>">
    <div class="col-sm-9"><input type="text" class="form-control" name="search" value="<?php echo e($examSearch); ?>" placeholder="Search by title, course code or course name"></div>
    <div class="col-sm-3"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search me-1"></i>Search</button></div>
</form>

<?php if (!$examinations): ?>
    <div class="alert alert-info">There are no examinations at the moment. Please check back later.</div>
<?php else: ?>
    <div class="row g-4">
        <?php foreach ($examinations as $exam): ?>
            <?php
            $display   = exam_display_status($exam);
            $used      = (int) $exam['attempts_used'];
            $max       = max(1, (int) $exam['max_attempts']);
            $remaining = max(0, $max - $used);
            ?>
            <div class="col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h2 class="h5 mb-0"><?php echo e($exam['title']); ?></h2>
                            <?php echo exam_status_badge($exam); ?>
                        </div>
                        <p class="text-muted mb-2"><?php echo e($exam['course_code'] . ' - ' . $exam['course_name']); ?></p>

                        <ul class="list-unstyled small mb-3">
                            <li><i class="bi bi-clock me-1"></i>Duration: <?php echo (int) $exam['duration_minutes']; ?> minutes</li>
                            <li><i class="bi bi-list-ol me-1"></i>Questions: <?php echo (int) $exam['question_count']; ?></li>
                            <li><i class="bi bi-trophy me-1"></i>Pass mark: <?php echo (int) $exam['pass_mark']; ?>%</li>
                            <li><i class="bi bi-arrow-repeat me-1"></i>Attempts: <?php echo $used; ?> / <?php echo $max; ?></li>
                            <?php if ($exam['available_from'] || $exam['available_until']): ?>
                                <li><i class="bi bi-calendar-week me-1"></i>
                                    <?php if ($exam['available_from']): ?>Opens <?php echo e(date('d M Y H:i', strtotime($exam['available_from']))); ?><?php endif; ?>
                                    <?php if ($exam['available_from'] && $exam['available_until']): ?> &middot; <?php endif; ?>
                                    <?php if ($exam['available_until']): ?>Closes <?php echo e(date('d M Y H:i', strtotime($exam['available_until']))); ?><?php endif; ?>
                                </li>
                            <?php endif; ?>
                        </ul>

                        <?php if (trim((string) $exam['instructions']) !== ''): ?>
                            <p class="small text-muted border-start border-3 ps-2">
                                <?php echo nl2br(e($exam['instructions'])); ?>
                            </p>
                        <?php endif; ?>

                        <div class="mt-auto">
                            <?php if ($exam['question_count'] < 1): ?>
                                <button class="btn btn-secondary w-100" disabled>No questions available</button>
                            <?php elseif ($exam['active_attempt_id']): ?>
                                <a class="btn btn-warning w-100" href="<?php echo url('student/take_exam.php'); ?>">
                                    <i class="bi bi-play-fill me-1"></i>Resume examination
                                </a>
                            <?php elseif ($remaining < 1): ?>
                                <a class="btn btn-outline-success w-100" href="<?php echo $exam['last_completed_id'] ? url('student/results.php?id=' . (int) $exam['last_completed_id']) : url('student/history.php'); ?>">
                                    <i class="bi bi-check2-circle me-1"></i>Completed - view result
                                </a>
                            <?php elseif ($display === 'NOT YET OPEN'): ?>
                                <button class="btn btn-outline-secondary w-100" disabled>
                                    NOT YET OPEN
                                </button>
                            <?php elseif ($display === 'CLOSED'): ?>
                                <button class="btn btn-outline-secondary w-100" disabled>
                                    CLOSED
                                </button>
                            <?php else: ?>
                                <a class="btn btn-primary w-100" href="<?php echo url('student/start_exam.php?exam=' . (int) $exam['id']); ?>">
                                    <i class="bi bi-play-fill me-1"></i>START EXAM
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
