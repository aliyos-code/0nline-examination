<?php
/**
 * Printable examination result (statement of result).
 *
 * Accessible by students (own attempts) and by administrators (any attempt).
 * The layout is deliberately plain so that it prints cleanly on A4.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$pdo       = db();
$attemptId = (int) input('id');
$viewer    = current_user();

$stmt = $pdo->prepare(
    'SELECT a.*, s.full_name, s.reg_number, s.email, s.department,
            e.title AS exam_title, e.course_code, e.course_name, e.pass_mark
     FROM exam_attempts a
     JOIN students s ON s.id = a.student_id
     JOIN examinations e ON e.id = a.examination_id
     WHERE a.id = ?'
);
$stmt->execute([$attemptId]);
$attempt = $stmt->fetch();

if (!$attempt) {
    set_flash('danger', 'Result not found.');
    redirect(is_admin() ? 'admin/results.php' : 'student/history.php');
}

// Access control: students may only print their own result.
if (!is_admin() && (int) $attempt['student_id'] !== (int) $viewer['id']) {
    set_flash('danger', 'That result is not available.');
    redirect('student/history.php');
}

$stmt = $pdo->prepare(
    'SELECT q.question_text, q.correct_option, sa.selected_option, sa.is_correct, aq.display_order
     FROM attempt_questions aq
     JOIN questions q ON q.id = aq.question_id
     LEFT JOIN student_answers sa
            ON sa.attempt_id = aq.attempt_id AND sa.question_id = aq.question_id
     WHERE aq.attempt_id = ?
     ORDER BY aq.display_order ASC'
);
$stmt->execute([$attemptId]);
$questions = $stmt->fetchAll();

$isAdmin  = is_admin();
$backUrl  = $isAdmin ? url('admin/result_view.php?id=' . $attemptId) : url('student/results.php?id=' . $attemptId);
$passed   = $attempt['pass_fail'] === 'pass';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Result Sheet &middot; <?php echo e($attempt['exam_title']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; }
        .sheet {
            background: #fff;
            max-width: 800px;
            margin: 2rem auto;
            padding: 2.5rem;
            border-radius: .5rem;
            box-shadow: 0 .25rem 1rem rgba(16,24,40,.08);
        }
        .sheet-head { border-bottom: 3px double #0f172a; padding-bottom: 1rem; }
        .institution { font-size: 1.15rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        .meta-label { font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; color: #64748b; }
        .score-box { border: 2px solid #0f172a; border-radius: .5rem; }
        table.answers { font-size: .9rem; }
        table.answers th { background: #f1f5f9; }
        .toolbar { max-width: 800px; margin: 1rem auto 0; }
        .sign-line { border-top: 1px solid #94a3b8; padding-top: .4rem; font-size: .8rem; color: #475569; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; }
            @page { size: A4; margin: 14mm; }
        }
    </style>
</head>
<body>

<div class="toolbar no-print d-flex justify-content-between">
    <a href="<?php echo e($backUrl); ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
    <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
        <i class="bi bi-printer me-1"></i>Print / Save as PDF
    </button>
</div>

<div class="sheet">
    <div class="sheet-head d-flex justify-content-between align-items-start">
        <div>
            <div class="institution">Online Examination System</div>
            <div class="text-muted">Statement of Examination Result</div>
        </div>
        <div class="text-end small text-muted">
            Ref: ATT-<?php echo str_pad((string) $attemptId, 6, '0', STR_PAD_LEFT); ?><br>
            Printed: <?php echo e(date('d M Y, H:i')); ?>
        </div>
    </div>

    <div class="row g-3 mt-1 mb-3">
        <div class="col-6">
            <div class="meta-label">Student</div>
            <div class="fw-semibold"><?php echo e($attempt['full_name']); ?></div>
            <div class="small text-muted">Reg. No: <?php echo e($attempt['reg_number']); ?></div>
        </div>
        <div class="col-6">
            <div class="meta-label">Examination</div>
            <div class="fw-semibold"><?php echo e($attempt['exam_title']); ?></div>
            <div class="small text-muted"><?php echo e($attempt['course_code'] . ' - ' . $attempt['course_name']); ?></div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="meta-label">Score</div>
            <div class="fs-5 fw-bold"><?php echo (int) $attempt['score']; ?>/<?php echo (int) $attempt['total_questions']; ?></div>
        </div>
        <div class="col-6 col-md-3">
            <div class="meta-label">Percentage</div>
            <div class="fs-5 fw-bold"><?php echo e($attempt['percentage']); ?>%</div>
        </div>
        <div class="col-6 col-md-3">
            <div class="meta-label">Pass mark</div>
            <div class="fs-5 fw-bold"><?php echo (int) $attempt['pass_mark']; ?>%</div>
        </div>
        <div class="col-6 col-md-3">
            <div class="meta-label">Result</div>
            <div class="fs-5 fw-bold <?php echo $passed ? 'text-success' : 'text-danger'; ?>">
                <?php echo $passed ? 'PASS' : 'FAIL'; ?>
            </div>
        </div>
    </div>

    <table class="table table-sm table-bordered answers mb-3">
        <thead>
            <tr>
                <th style="width:45px">#</th>
                <th>Question</th>
                <th style="width:90px">Selected</th>
                <th style="width:90px">Correct</th>
                <th style="width:90px">Result</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$questions): ?>
            <tr><td colspan="5" class="text-center text-muted py-3">No question data recorded.</td></tr>
        <?php else: $n = 1; foreach ($questions as $q): ?>
            <tr>
                <td><?php echo $n++; ?></td>
                <td><?php echo e($q['question_text']); ?></td>
                <td class="text-center"><?php echo $q['selected_option'] ? e($q['selected_option']) : '-'; ?></td>
                <td class="text-center fw-semibold"><?php echo e($q['correct_option']); ?></td>
                <td class="text-center">
                    <?php if ((int) $q['is_correct'] === 1): ?>
                        <span class="text-success fw-semibold">Correct</span>
                    <?php else: ?>
                        <span class="text-danger fw-semibold">Wrong</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div class="row g-3 small">
        <div class="col-6">
            <div class="meta-label">Started</div>
            <div><?php echo e($attempt['start_time']); ?></div>
        </div>
        <div class="col-6">
            <div class="meta-label">Submitted</div>
            <div><?php echo e($attempt['end_time'] ? $attempt['end_time'] : '-'); ?></div>
        </div>
    </div>

    <div class="row g-4 mt-3">
        <div class="col-6">
            <div class="sign-line">&nbsp;</div>
            <div class="mt-1">Invigilator / Administrator</div>
        </div>
        <div class="col-6 text-end">
            <div class="sign-line">&nbsp;</div>
            <div class="mt-1">Student signature</div>
        </div>
    </div>

    <p class="text-center text-muted small mt-4 mb-0">
        This is a computer generated result sheet. Any alteration is invalid.
    </p>
</div>

<?php if (isset($_GET['auto']) && $_GET['auto'] === '1'): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
