<?php
/**
 * Administrator: view, search, filter and sort all examination results.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pdo = db();

$examinations = $pdo->query('SELECT id, title, course_code FROM examinations ORDER BY title ASC')->fetchAll();

$examFilter   = (int) input('exam');
$search       = input('search');
$statusFilter = input('status');
$sort         = input('sort', 'date_desc');

$where  = ["a.status = 'completed'"];
$params = [];

if ($examFilter > 0) {
    $where[]  = 'a.examination_id = ?';
    $params[] = $examFilter;
}
if ($search !== '') {
    $where[]  = '(s.full_name LIKE ? OR s.reg_number LIKE ? OR s.email LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($statusFilter === 'pass' || $statusFilter === 'fail') {
    $where[]  = 'a.pass_fail = ?';
    $params[] = $statusFilter;
}

$orderBy = 'a.end_time DESC';
switch ($sort) {
    case 'score_desc': $orderBy = 'a.percentage DESC'; break;
    case 'score_asc':  $orderBy = 'a.percentage ASC';  break;
    case 'name_asc':   $orderBy = 's.full_name ASC';   break;
    case 'date_asc':   $orderBy = 'a.end_time ASC';    break;
    default:           $orderBy = 'a.end_time DESC';
}

$sql = 'SELECT a.*, s.full_name, s.reg_number, e.title AS exam_title, e.course_code, e.course_name
        FROM exam_attempts a
        JOIN students s ON s.id = a.student_id
        JOIN examinations e ON e.id = a.examination_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY ' . $orderBy;
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll();

$page_title = 'Results';
$active = 'results';
require __DIR__ . '/../includes/header.php';
?>
<div class="row">
    <div class="col-lg-3 mb-4">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    </div>

    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h1 class="h3 mb-0">Examination Results</h1>
            <a href="<?php echo url('admin/reports.php'); ?>" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-download me-1"></i>Reports &amp; Export
            </a>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <form class="row g-2" method="get" action="<?php echo url('admin/results.php'); ?>">
                    <div class="col-md-3">
                        <select class="form-select" name="exam">
                            <option value="0">All examinations</option>
                            <?php foreach ($examinations as $exam): ?>
                                <option value="<?php echo (int) $exam['id']; ?>" <?php echo $examFilter === (int) $exam['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($exam['course_code'] . ' - ' . $exam['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="status">
                            <option value="">All statuses</option>
                            <option value="pass" <?php echo $statusFilter === 'pass' ? 'selected' : ''; ?>>Pass</option>
                            <option value="fail" <?php echo $statusFilter === 'fail' ? 'selected' : ''; ?>>Fail</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" name="sort">
                            <option value="date_desc" <?php echo $sort === 'date_desc' ? 'selected' : ''; ?>>Newest first</option>
                            <option value="date_asc"  <?php echo $sort === 'date_asc' ? 'selected' : ''; ?>>Oldest first</option>
                            <option value="score_desc" <?php echo $sort === 'score_desc' ? 'selected' : ''; ?>>Highest score</option>
                            <option value="score_asc"  <?php echo $sort === 'score_asc' ? 'selected' : ''; ?>>Lowest score</option>
                            <option value="name_asc"   <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Student name</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="text" class="form-control" name="search" value="<?php echo e($search); ?>" placeholder="Student name / reg no.">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apply filters</button>
                        <a class="btn btn-outline-secondary" href="<?php echo url('admin/results.php'); ?>">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Examination</th>
                            <th class="text-center">Score</th>
                            <th class="text-center">%</th>
                            <th class="text-center">Status</th>
                            <th>Completed</th>
                            <th class="text-end">View</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$results): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No results match the selected filters.</td></tr>
                    <?php else: foreach ($results as $row): ?>
                        <tr>
                            <td><?php echo e($row['full_name']); ?><br><small class="text-muted"><?php echo e($row['reg_number']); ?></small></td>
                            <td><?php echo e($row['course_code']); ?><br><small class="text-muted"><?php echo e($row['exam_title']); ?></small></td>
                            <td class="text-center"><?php echo (int) $row['score']; ?>/<?php echo (int) $row['total_questions']; ?></td>
                            <td class="text-center"><?php echo e($row['percentage']); ?>%</td>
                            <td class="text-center"><?php echo pass_fail_badge($row['pass_fail']); ?></td>
                            <td><?php echo e($row['end_time']); ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo url('admin/result_view.php?id=' . (int) $row['id']); ?>" title="View result">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo url('print/result.php?id=' . (int) $row['id']); ?>" title="Print result sheet">
                                    <i class="bi bi-printer"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white text-muted small">
                <?php echo count($results); ?> result(s) found.
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
