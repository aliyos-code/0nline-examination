<?php
/**
 * Student: complete examination history including in-progress attempts.
 */
require_once __DIR__ . '/../includes/auth.php';
require_student();

$pdo       = db();
$studentId = (int) current_user()['id'];

$stmt = $pdo->prepare(
    'SELECT a.*, e.title AS exam_title, e.course_code, e.course_name
     FROM exam_attempts a
     JOIN examinations e ON e.id = a.examination_id
     WHERE a.student_id = ?
     ORDER BY a.start_time DESC'
);
$stmt->execute([$studentId]);
$attempts = $stmt->fetchAll();

$histSearch = input('search');
if ($histSearch !== '') {
    $attempts = array_values(array_filter($attempts, function ($r) use ($histSearch) {
        return stripos($r['exam_title'] . ' ' . $r['course_code'], $histSearch) !== false;
    }));
}

$page_title = 'Examination History';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-4">Examination History</h1>
<form class="row g-2 mb-3" method="get" action="<?php echo url('student/history.php'); ?>">
    <div class="col-sm-9"><input type="text" class="form-control" name="search" value="<?php echo e($histSearch); ?>" placeholder="Search by examination or course code"></div>
    <div class="col-sm-3"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search me-1"></i>Search</button></div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Examination</th>
                    <th>Course</th>
                    <th>Started</th>
                    <th class="text-center">Score</th>
                    <th class="text-center">%</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$attempts): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">You have not attempted any examination yet.</td></tr>
            <?php else: foreach ($attempts as $row): ?>
                <tr>
                    <td><?php echo e($row['exam_title']); ?></td>
                    <td><?php echo e($row['course_code']); ?></td>
                    <td><?php echo e($row['start_time']); ?></td>
                    <td class="text-center"><?php echo (int) $row['score']; ?>/<?php echo (int) $row['total_questions']; ?></td>
                    <td class="text-center"><?php echo $row['percentage'] !== null ? e($row['percentage']) . '%' : '-'; ?></td>
                    <td class="text-center">
                        <?php if ($row['status'] === 'completed'): ?>
                            <?php echo pass_fail_badge($row['pass_fail']); ?>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">In progress</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <?php if ($row['status'] === 'completed'): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?php echo url('student/results.php?id=' . (int) $row['id']); ?>">
                                <i class="bi bi-eye me-1"></i>View
                            </a>
                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo url('print/result.php?id=' . (int) $row['id']); ?>" title="Print result sheet">
                                <i class="bi bi-printer"></i>
                            </a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-warning" href="<?php echo url('student/take_exam.php'); ?>">Resume</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
