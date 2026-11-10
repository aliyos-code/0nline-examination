<?php
/**
 * Administrator: detailed view of a single examination attempt.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pdo = db();
$id  = (int) input('id');

$stmt = $pdo->prepare(
    'SELECT a.*, s.full_name, s.reg_number, s.email, s.department,
            e.title AS exam_title, e.course_code, e.course_name, e.pass_mark
     FROM exam_attempts a
     JOIN students s ON s.id = a.student_id
     JOIN examinations e ON e.id = a.examination_id
     WHERE a.id = ?'
);
$stmt->execute([$id]);
$attempt = $stmt->fetch();

if (!$attempt) {
    set_flash('danger', 'Result not found.');
    redirect('admin/results.php');
}

$stmt = $pdo->prepare(
    'SELECT q.question_text, q.option_a, q.option_b, q.option_c, q.option_d,
            q.correct_option, sa.selected_option, sa.is_correct, aq.display_order
     FROM attempt_questions aq
     JOIN questions q ON q.id = aq.question_id
     LEFT JOIN student_answers sa
            ON sa.attempt_id = aq.attempt_id AND sa.question_id = aq.question_id
     WHERE aq.attempt_id = ?
     ORDER BY aq.display_order ASC'
);
$stmt->execute([$id]);
$questions = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT event_type, detail, ip_address, created_at
     FROM attempt_events
     WHERE attempt_id = ?
     ORDER BY id ASC'
);
$stmt->execute([$id]);
$events = $stmt->fetchAll();

$page_title = 'Result Detail';
$active = 'results';
require __DIR__ . '/../includes/header.php';
?>
<div class="row">
    <div class="col-lg-3 mb-4">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    </div>

    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h1 class="h3 mb-0">Result Detail</h1>
            <div class="d-flex gap-2">
                <a href="<?php echo url('print/result.php?id=' . (int) $id); ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-printer me-1"></i>Print / Save PDF
                </a>
                <a href="<?php echo url('admin/results.php'); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to results
                </a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold">Student</div>
                    <div class="card-body">
                        <p class="mb-1"><strong><?php echo e($attempt['full_name']); ?></strong></p>
                        <p class="mb-1 text-muted">Reg. No: <?php echo e($attempt['reg_number']); ?></p>
                        <p class="mb-1 text-muted">Email: <?php echo e($attempt['email']); ?></p>
                        <p class="mb-0 text-muted">Department: <?php echo e($attempt['department'] ? $attempt['department'] : '-'); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold">Examination</div>
                    <div class="card-body">
                        <p class="mb-1"><strong><?php echo e($attempt['exam_title']); ?></strong></p>
                        <p class="mb-1 text-muted"><?php echo e($attempt['course_code'] . ' - ' . $attempt['course_name']); ?></p>
                        <p class="mb-1 text-muted">Started: <?php echo e($attempt['start_time']); ?></p>
                        <p class="mb-0 text-muted">Submitted: <?php echo e($attempt['end_time'] ? $attempt['end_time'] : '-'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card text-center shadow-sm">
                    <div class="card-body">
                        <div class="h4 mb-0"><?php echo (int) $attempt['score']; ?>/<?php echo (int) $attempt['total_questions']; ?></div>
                        <div class="small text-muted">Score</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center shadow-sm">
                    <div class="card-body">
                        <div class="h4 mb-0 text-success"><?php echo (int) $attempt['correct_answers']; ?></div>
                        <div class="small text-muted">Correct</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center shadow-sm">
                    <div class="card-body">
                        <div class="h4 mb-0 text-danger"><?php echo (int) $attempt['wrong_answers']; ?></div>
                        <div class="small text-muted">Wrong</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center shadow-sm">
                    <div class="card-body">
                        <div class="h4 mb-0"><?php echo e($attempt['percentage']); ?>%</div>
                        <div class="small text-muted">Percentage</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="alert alert-<?php echo $attempt['pass_fail'] === 'pass' ? 'success' : 'danger'; ?>">
            Final status: <strong><?php echo strtoupper(e($attempt['pass_fail'])); ?></strong>
            (pass mark <?php echo (int) $attempt['pass_mark']; ?>%)
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-shield-exclamation me-1"></i>Proctoring &amp; Integrity
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="small text-muted">IP address</div>
                        <div><?php echo $attempt['ip_address'] ? e($attempt['ip_address']) : '<span class="text-muted">-</span>'; ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted">Browser / device</div>
                        <div class="small text-break"><?php echo $attempt['user_agent'] ? e($attempt['user_agent']) : '<span class="text-muted">-</span>'; ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="small text-muted">Focus losses</div>
                        <div>
                            <?php $fc = (int) $attempt['focus_lost_count']; ?>
                            <span class="badge bg-<?php echo $fc === 0 ? 'success' : ($fc < 3 ? 'warning text-dark' : 'danger'); ?>"><?php echo $fc; ?></span>
                        </div>
                    </div>
                </div>
                <?php if ($events): ?>
                    <hr>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr><th>Event</th><th>Detail</th><th class="text-end">When</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($events as $ev): ?>
                                <tr>
                                    <td><span class="badge bg-secondary"><?php echo e($ev['event_type']); ?></span></td>
                                    <td class="small text-muted"><?php echo $ev['detail'] ? e($ev['detail']) : '-'; ?></td>
                                    <td class="text-end small text-muted"><?php echo e($ev['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mt-3 mb-0">No integrity events were recorded.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Answer Breakdown</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px">#</th>
                            <th>Question</th>
                            <th class="text-center">Selected</th>
                            <th class="text-center">Correct</th>
                            <th class="text-center">Result</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$questions): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No question data recorded.</td></tr>
                    <?php else: $n = 1; foreach ($questions as $q): ?>
                        <tr>
                            <td><?php echo $n++; ?></td>
                            <td><?php echo e($q['question_text']); ?></td>
                            <td class="text-center"><?php echo $q['selected_option'] ? e($q['selected_option']) : '<span class="text-muted">-</span>'; ?></td>
                            <td class="text-center"><span class="badge bg-dark"><?php echo e($q['correct_option']); ?></span></td>
                            <td class="text-center">
                                <?php if ((int) $q['is_correct'] === 1): ?>
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill text-danger"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
