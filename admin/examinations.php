<?php
/**
 * Administrator: create, edit, delete and activate/deactivate examinations.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pdo    = db();
auto_close_expired_exams($pdo);
$errors = [];

// ---------------------------------------------------------------------------
// Actions (POST)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        set_flash('danger', 'Your session expired. Please try again.');
        redirect('admin/examinations.php');
    }

    $action = input('action');

    if ($action === 'delete') {
        $id = (int) input('id');
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM examinations WHERE id = ?');
            $stmt->execute([$id]);
            audit_log('exam.delete', 'examination', $id);
            set_flash('success', 'Examination and its questions were deleted.');
        }
        redirect('admin/examinations.php');
    }

    if ($action === 'toggle' || $action === 'open' || $action === 'close') {
        $id = (int) input('id');
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT status FROM examinations WHERE id = ?');
            $stmt->execute([$id]);
            $cur = $stmt->fetchColumn();
            if ($action === 'open') {
                $pdo->prepare("UPDATE examinations SET status = 'active' WHERE id = ?")->execute([$id]);
                audit_log('exam.open', 'examination', $id);
                set_flash('success', 'Examination is now OPEN. Students can start it.');
            } elseif ($action === 'close') {
                $pdo->prepare("UPDATE examinations SET status = 'closed' WHERE id = ?")->execute([$id]);
                audit_log('exam.close', 'examination', $id);
                set_flash('success', 'Examination is now CLOSED.');
            } else {
                // Backwards-compatible toggle: active -> NOT YET OPEN, otherwise OPEN.
                $next = ($cur === 'active') ? 'inactive' : 'active';
                $pdo->prepare('UPDATE examinations SET status = ? WHERE id = ?')->execute([$next, $id]);
                audit_log('exam.toggle', 'examination', $id, $next);
                set_flash('success', $next === 'active' ? 'Examination is now OPEN.' : 'Examination is now NOT YET OPEN.');
            }
        }
        redirect('admin/examinations.php');
    }

    if ($action === 'save') {
        $id             = (int) input('id');
        $title          = input('title');
        $courseName     = input('course_name');
        $courseCode     = input('course_code');
        $instructions   = input('instructions');
        $duration       = (int) input('duration_minutes');
        $totalQuestions = (int) input('total_questions');
        $passMark       = (int) input('pass_mark');
        $status         = input('status', 'inactive');

        // Optional scheduling window (datetime-local) -> NULL when left blank.
        $fromRaw       = input('available_from');
        $untilRaw      = input('available_until');
        $availableFrom = ($fromRaw !== '' && strtotime($fromRaw) !== false)
            ? date('Y-m-d H:i:s', strtotime($fromRaw)) : null;
        $availableUntil = ($untilRaw !== '' && strtotime($untilRaw) !== false)
            ? date('Y-m-d H:i:s', strtotime($untilRaw)) : null;

        // Per-student attempt allowance (1 = single sitting, the sane default).
        $maxAttempts = (int) input('max_attempts');
        if ($maxAttempts < 1)    { $maxAttempts = 1; }
        if ($maxAttempts > 100)  { $maxAttempts = 100; }

        if ($title === '')          { $errors[] = 'Examination title is required.'; }
        if ($courseName === '')     { $errors[] = 'Course name is required.'; }
        if ($courseCode === '')     { $errors[] = 'Course code is required.'; }
        if ($duration < 1 || $duration > 600) { $errors[] = 'Duration must be between 1 and 600 minutes.'; }
        if ($totalQuestions < 1)    { $errors[] = 'Number of questions must be at least 1.'; }
        if ($passMark < 0 || $passMark > 100) { $errors[] = 'Pass mark must be between 0 and 100.'; }
        if (!in_array($status, ['active', 'inactive', 'closed'], true)) { $status = 'inactive'; }
        if ($id === 0) { $status = 'inactive'; } // Always NOT YET OPEN on creation.
        if ($availableFrom !== null && $availableUntil !== null
            && strtotime($availableFrom) >= strtotime($availableUntil)) {
            $errors[] = 'The opening time must be earlier than the closing time.';
        }

        if (!$errors) {
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE examinations
                     SET title = ?, course_name = ?, course_code = ?, instructions = ?,
                         duration_minutes = ?, total_questions = ?, pass_mark = ?, status = ?,
                         available_from = ?, available_until = ?, max_attempts = ?
                     WHERE id = ?'
                );
                $stmt->execute([$title, $courseName, $courseCode, $instructions,
                                $duration, $totalQuestions, $passMark, $status,
                                $availableFrom, $availableUntil, $maxAttempts, $id]);
                audit_log('exam.update', 'examination', $id, $title);
                set_flash('success', 'Examination updated successfully.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO examinations
                        (title, course_name, course_code, instructions,
                         duration_minutes, total_questions, pass_mark, status,
                         available_from, available_until, max_attempts)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                // New examinations are always saved NOT YET OPEN (inactive).
                // The admin must click OPEN EXAM before students can start.
                $status = 'inactive';
                $stmt->execute([$title, $courseName, $courseCode, $instructions,
                                $duration, $totalQuestions, $passMark, $status,
                                $availableFrom, $availableUntil, $maxAttempts]);
                audit_log('exam.create', 'examination', (int) $pdo->lastInsertId(), $title);
                set_flash('success', 'Examination created as NOT YET OPEN. Click OPEN EXAM when you are ready for students to start.');
            }
            redirect('admin/examinations.php');
        }
    }
}

// ---------------------------------------------------------------------------
// Editing state
// ---------------------------------------------------------------------------
$editing = null;
$editId  = (int) input('edit');
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM examinations WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch();
}

$examSearch = input('exam_search');
$examStatus = input('exam_status');
$examWhere = '';
$examParams = [];
if ($examSearch !== '') {
    $examWhere = 'WHERE e.title LIKE ? OR e.course_code LIKE ? OR e.course_name LIKE ?';
    $like = '%' . $examSearch . '%';
    $examParams = [$like, $like, $like];
} elseif (in_array($examStatus, ['active', 'inactive', 'closed'], true)) {
    $examWhere = 'WHERE e.status = ?';
    $examParams = [$examStatus];
}
$examStmt = $pdo->prepare(
    'SELECT e.*,
            (SELECT COUNT(*) FROM questions q WHERE q.examination_id = e.id) AS question_count,
            (SELECT COUNT(*) FROM exam_attempts a WHERE a.examination_id = e.id) AS attempt_count
     FROM examinations e
     ' . $examWhere . '
     ORDER BY e.created_at DESC'
);
$examStmt->execute($examParams);
$examinations = $examStmt->fetchAll();

$page_title = 'Manage Examinations';
$active = 'examinations';
require __DIR__ . '/../includes/header.php';

$form = [
    'id'               => $editing ? $editing['id'] : '',
    'title'            => $editing ? $editing['title'] : '',
    'course_name'      => $editing ? $editing['course_name'] : '',
    'course_code'      => $editing ? $editing['course_code'] : '',
    'instructions'     => $editing ? $editing['instructions'] : '',
    'duration_minutes' => $editing ? $editing['duration_minutes'] : 30,
    'total_questions'  => $editing ? $editing['total_questions'] : 10,
    'pass_mark'        => $editing ? $editing['pass_mark'] : 50,
    'status'           => $editing ? $editing['status'] : 'inactive',
    'available_from'   => ($editing && $editing['available_from']) ? date('Y-m-d\TH:i', strtotime($editing['available_from'])) : '',
    'available_until'  => ($editing && $editing['available_until']) ? date('Y-m-d\TH:i', strtotime($editing['available_until'])) : '',
    'max_attempts'     => $editing ? $editing['max_attempts'] : 1,
];
?>
<div class="row">
    <div class="col-lg-3 mb-4">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    </div>

    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h1 class="h3 mb-0">Examinations</h1>
            <a href="<?php echo url('admin/ai_exam.php'); ?>" class="btn btn-primary btn-sm">
                <i class="bi bi-robot me-1"></i>Create an exam with AI
            </a>
        </div>

        <div class="alert alert-info d-flex gap-2">
            <i class="bi bi-lightbulb-fill"></i>
            <div>
                Want an exam in a hurry? Use the <strong>AI Generator</strong> to describe any topic and
                get a ready-to-edit examination with questions, or add questions to an existing exam
                from its Questions screen.
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                <?php echo $editing ? 'Edit Examination' : 'Create New Examination'; ?>
            </div>
            <div class="card-body">
                <form method="post" action="<?php echo url('admin/examinations.php'); ?>" novalidate>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo e($form['id']); ?>">

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Examination title</label>
                            <input type="text" class="form-control" name="title" required
                                   value="<?php echo e($form['title']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Course code</label>
                            <input type="text" class="form-control" name="course_code" required
                                   value="<?php echo e($form['course_code']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Course name</label>
                            <input type="text" class="form-control" name="course_name" required
                                   value="<?php echo e($form['course_name']); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Duration (min)</label>
                            <input type="number" class="form-control" name="duration_minutes" min="1" max="600" required
                                   value="<?php echo e($form['duration_minutes']); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">No. of questions</label>
                            <input type="number" class="form-control" name="total_questions" min="1" required
                                   value="<?php echo e($form['total_questions']); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Pass mark (%)</label>
                            <input type="number" class="form-control" name="pass_mark" min="0" max="100" required
                                   value="<?php echo e($form['pass_mark']); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Instructions</label>
                            <textarea class="form-control" name="instructions" rows="3"><?php echo e($form['instructions']); ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="inactive" <?php echo $form['status'] === 'inactive' ? 'selected' : ''; ?>>NOT YET OPEN</option>
                                <option value="active" <?php echo $form['status'] === 'active' ? 'selected' : ''; ?>>OPEN</option>
                                <option value="closed" <?php echo $form['status'] === 'closed' ? 'selected' : ''; ?>>CLOSED</option>
                            </select>
                            <?php if (!$editing): ?><div class="form-text">New examinations are always saved NOT YET OPEN. Click OPEN EXAM in the list to open.</div><?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Attempts allowed / student</label>
                            <input type="number" class="form-control" name="max_attempts" min="1" max="100"
                                   value="<?php echo e($form['max_attempts']); ?>">
                            <div class="form-text">1 = single sitting.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Opens (optional)</label>
                            <input type="datetime-local" class="form-control" name="available_from"
                                   value="<?php echo e($form['available_from']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Closes (optional)</label>
                            <input type="datetime-local" class="form-control" name="available_until"
                                   value="<?php echo e($form['available_until']); ?>">
                            <div class="form-text">Leave blank for no restriction.</div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i><?php echo $editing ? 'Update' : 'Create'; ?>
                        </button>
                        <?php if ($editing): ?>
                            <a href="<?php echo url('admin/examinations.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <form class="row g-2 mb-3" method="get" action="<?php echo url('admin/examinations.php'); ?>">
            <div class="col-md-6"><input type="text" class="form-control" name="exam_search" value="<?php echo e($examSearch); ?>" placeholder="Search by title, course code or course name"></div>
            <div class="col-md-3"><select class="form-select" name="exam_status">
                <option value="">All statuses</option>
                <option value="active" <?php echo $examStatus === 'active' ? 'selected' : ''; ?>>OPEN</option>
                <option value="inactive" <?php echo $examStatus === 'inactive' ? 'selected' : ''; ?>>NOT YET OPEN</option>
                <option value="closed" <?php echo $examStatus === 'closed' ? 'selected' : ''; ?>>CLOSED</option>
            </select></div>
            <div class="col-md-3"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search me-1"></i>Search</button></div>
        </form>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Title</th>
                            <th>Course</th>
                            <th class="text-center">Duration</th>
                            <th class="text-center">Questions</th>
                            <th class="text-center">Pass</th>
                            <th>Schedule</th>
                            <th class="text-center">Attempts</th>
                            <th class="text-center">Allowed</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$examinations): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No examinations created yet.</td></tr>
                    <?php else: foreach ($examinations as $exam): ?>
                        <tr>
                            <td><?php echo e($exam['title']); ?></td>
                            <td><?php echo e($exam['course_code']); ?><br><small class="text-muted"><?php echo e($exam['course_name']); ?></small></td>
                            <td class="text-center"><?php echo (int) $exam['duration_minutes']; ?>m</td>
                            <td class="text-center"><?php echo (int) $exam['question_count']; ?> / <?php echo (int) $exam['total_questions']; ?></td>
                            <td class="text-center"><?php echo (int) $exam['pass_mark']; ?>%</td>
                            <td class="small">
                                <?php
                                if (!$exam['available_from'] && !$exam['available_until']) {
                                    echo 'Always open';
                                } else {
                                    echo $exam['available_from']
                                        ? '<i class="bi bi-box-arrow-in-right me-1"></i>' . e(date('d M Y H:i', strtotime($exam['available_from'])))
                                        : '<i class="bi bi-box-arrow-in-right me-1"></i>any time';
                                    echo '<br>';
                                    echo $exam['available_until']
                                        ? '<i class="bi bi-box-arrow-right me-1"></i>' . e(date('d M Y H:i', strtotime($exam['available_until'])))
                                        : '<i class="bi bi-box-arrow-right me-1"></i>no close';
                                }
                                ?>
                            </td>
                            <td class="text-center"><?php echo (int) $exam['attempt_count']; ?></td>
                            <td class="text-center"><?php echo (int) $exam['max_attempts']; ?></td>
                            <td><?php echo exam_status_badge($exam); ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo url('admin/questions.php?exam=' . (int) $exam['id']); ?>" title="Manage questions">
                                    <i class="bi bi-list-check"></i>
                                </a>
                                <a class="btn btn-sm btn-outline-dark" href="<?php echo url('admin/assign_exam.php?exam=' . (int) $exam['id']); ?>" title="Assign to students">
                                    <i class="bi bi-person-check"></i>
                                </a>
                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo url('admin/examinations.php?edit=' . (int) $exam['id']); ?>" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if (exam_display_status($exam) !== 'OPEN'): ?>
                                <form method="post" action="<?php echo url('admin/examinations.php'); ?>" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="open">
                                    <input type="hidden" name="id" value="<?php echo (int) $exam['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-success" title="Open examination for students">
                                        OPEN EXAM
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="post" action="<?php echo url('admin/examinations.php'); ?>" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="close">
                                    <input type="hidden" name="id" value="<?php echo (int) $exam['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" data-confirm="Close this examination for students?" title="Close examination">
                                        CLOSE
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="post" action="<?php echo url('admin/examinations.php'); ?>" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $exam['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete this examination, its questions and results?" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
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
