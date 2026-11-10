<?php
/**
 * Student dashboard: profile summary, available examinations and recent results.
 */
require_once __DIR__ . '/../includes/auth.php';
require_student();

$pdo       = db();
auto_close_expired_exams($pdo);
$studentId = (int) current_user()['id'];

// Full profile (session only stores a subset).
$stmt = $pdo->prepare('SELECT * FROM students WHERE id = ?');
$stmt->execute([$studentId]);
$student = $stmt->fetch();

// Statistic counts.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_attempts WHERE student_id = ? AND status = 'completed'");
$stmt->execute([$studentId]);
$completedCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_attempts WHERE student_id = ? AND status = 'in_progress'");
$stmt->execute([$studentId]);
$inProgressCount = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_attempts WHERE student_id = ? AND pass_fail = 'pass'");
$stmt->execute([$studentId]);
$passCount = (int) $stmt->fetchColumn();

// All examinations visible to this student (NOT YET OPEN / OPEN / CLOSED),
// filtered by assignment when assignments exist.
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

// Recent results.
$stmt = $pdo->prepare(
    "SELECT a.*, e.title AS exam_title, e.course_code
     FROM exam_attempts a
     JOIN examinations e ON e.id = a.examination_id
     WHERE a.student_id = ? AND a.status = 'completed'
     ORDER BY a.end_time DESC
     LIMIT 5"
);
$stmt->execute([$studentId]);
$recentResults = $stmt->fetchAll();

// Announcements for students.
$stmt = $pdo->prepare(
    "SELECT a.title, a.body, a.created_at, ad.full_name AS author
     FROM announcements a
     LEFT JOIN admins ad ON ad.id = a.created_by
     WHERE a.audience IN ('students', 'all')
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT 5"
);
$stmt->execute();
$announcements = $stmt->fetchAll();

$page_title = 'Student Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-4">Welcome, <?php echo e($student['full_name']); ?></h1>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card bg-brand h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?php echo count($examinations); ?></div>
                    <div class="small">Available</div>
                </div>
                <i class="bi bi-journal-text stat-icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card bg-teal h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?php echo $completedCount; ?></div>
                    <div class="small">Completed</div>
                </div>
                <i class="bi bi-check2-circle stat-icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card bg-amber h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?php echo $passCount; ?></div>
                    <div class="small">Passed</div>
                </div>
                <i class="bi bi-trophy stat-icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card bg-rose h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <div class="stat-value"><?php echo $inProgressCount; ?></div>
                    <div class="small">In Progress</div>
                </div>
                <i class="bi bi-hourglass-split stat-icon"></i>
            </div>
        </div>
    </div>
</div>

<?php if ($announcements): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-megaphone me-1"></i>Announcements</span>
        <span class="badge bg-primary"><?php echo count($announcements); ?></span>
    </div>
    <div class="list-group list-group-flush">
        <?php foreach ($announcements as $notice): ?>
            <div class="list-group-item py-3">
                <div class="fw-semibold"><?php echo e($notice['title']); ?></div>
                <div class="small text-muted mb-1"><?php echo nl2br(e($notice['body'])); ?></div>
                <div class="small text-muted fst-italic">
                    <?php echo e(date('d M Y, H:i', strtotime($notice['created_at']))); ?>
                    <?php if ($notice['author']): ?> &middot; <?php echo e($notice['author']); ?><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">My Information</div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-5">Full name</dt><dd class="col-sm-7"><?php echo e($student['full_name']); ?></dd>
                    <dt class="col-sm-5">Registration No.</dt><dd class="col-sm-7"><?php echo e($student['reg_number']); ?></dd>
                    <dt class="col-sm-5">Email</dt><dd class="col-sm-7"><?php echo e($student['email']); ?></dd>
                    <dt class="col-sm-5">Department</dt><dd class="col-sm-7"><?php echo e($student['department'] ? $student['department'] : '-'); ?></dd>
                    <dt class="col-sm-5">Registered</dt><dd class="col-sm-7 mb-0"><?php echo e($student['created_at']); ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span>Available Examinations</span>
                <a href="<?php echo url('student/examinations.php'); ?>" class="btn btn-sm btn-outline-primary">View all</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Examination</th><th class="text-center">Status</th><th class="text-center">Duration</th><th class="text-end">Action</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$examinations): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No examinations available.</td></tr>
                    <?php else: foreach (array_slice($examinations, 0, 4) as $exam): ?>
                        <tr>
                            <td><?php echo e($exam['title']); ?><br><small class="text-muted"><?php echo e($exam['course_code']); ?></small></td>
                            <td class="text-center"><?php echo exam_status_badge($exam); ?></td>
                            <td class="text-center"><?php echo (int) $exam['duration_minutes']; ?>m</td>
                            <td class="text-end">
                                <?php
                                $display   = exam_display_status($exam);
                                $used      = (int) $exam['attempts_used'];
                                $max       = max(1, (int) $exam['max_attempts']);
                                $remaining = max(0, $max - $used);
                                ?>
                                <?php if ($exam['active_attempt_id']): ?>
                                    <a class="btn btn-sm btn-warning" href="<?php echo url('student/take_exam.php'); ?>">Resume</a>
                                <?php elseif ($remaining < 1): ?>
                                    <span class="badge bg-secondary">Completed</span>
                                <?php elseif ($display === 'NOT YET OPEN'): ?>
                                    <button class="btn btn-sm btn-outline-secondary" disabled>NOT YET OPEN</button>
                                <?php elseif ($display === 'CLOSED'): ?>
                                    <button class="btn btn-sm btn-outline-secondary" disabled>CLOSED</button>
                                <?php else: ?>
                                    <a class="btn btn-sm btn-primary" href="<?php echo url('student/start_exam.php?exam=' . (int) $exam['id']); ?>">START EXAM</a>
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

<div class="card shadow-sm mt-4">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Recent Results</span>
        <a href="<?php echo url('student/history.php'); ?>" class="btn btn-sm btn-outline-primary">Full history</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Examination</th><th class="text-center">Score</th><th class="text-center">%</th><th class="text-center">Status</th><th>Date</th><th class="text-end"></th></tr>
            </thead>
            <tbody>
            <?php if (!$recentResults): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">You have not completed any examination yet.</td></tr>
            <?php else: foreach ($recentResults as $row): ?>
                <tr>
                    <td><?php echo e($row['exam_title']); ?><br><small class="text-muted"><?php echo e($row['course_code']); ?></small></td>
                    <td class="text-center"><?php echo (int) $row['score']; ?>/<?php echo (int) $row['total_questions']; ?></td>
                    <td class="text-center"><?php echo e($row['percentage']); ?>%</td>
                    <td class="text-center"><?php echo pass_fail_badge($row['pass_fail']); ?></td>
                    <td><?php echo e($row['end_time']); ?></td>
                    <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo url('student/results.php?id=' . (int) $row['id']); ?>">View</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
