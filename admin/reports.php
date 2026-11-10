<?php
/**
 * Administrator: examination performance report and CSV export.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pdo = db();

$examinations = $pdo->query('SELECT id, title, course_code FROM examinations ORDER BY title ASC')->fetchAll();
$examFilter   = (int) input('exam');

// Build the shared filter clause.
$where  = ["a.status = 'completed'"];
$params = [];
if ($examFilter > 0) {
    $where[]  = 'a.examination_id = ?';
    $params[] = $examFilter;
}
$whereSql = implode(' AND ', $where);

// ---------------------------------------------------------------------------
// CSV export (must run before any HTML output)
// ---------------------------------------------------------------------------
if (input('export') === 'csv') {
    $sql = "SELECT s.full_name, s.reg_number, e.title AS exam_title,
                   e.course_name, e.course_code,
                   a.score, a.total_questions, a.percentage, a.pass_fail, a.end_time
            FROM exam_attempts a
            JOIN students s ON s.id = a.student_id
            JOIN examinations e ON e.id = a.examination_id
            WHERE {$whereSql}
            ORDER BY e.title ASC, s.full_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $filename = 'exam_results_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens the file with correct encoding.
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, [
        'Student Name', 'Student ID', 'Examination', 'Course',
        'Score', 'Total Questions', 'Percentage', 'Status', 'Examination Date',
    ]);
    foreach ($rows as $row) {
        fputcsv($output, [
            $row['full_name'],
            $row['reg_number'],
            $row['exam_title'],
            $row['course_code'] . ' - ' . $row['course_name'],
            $row['score'],
            $row['total_questions'],
            $row['percentage'] . '%',
            strtoupper($row['pass_fail']),
            $row['end_time'],
        ]);
    }
    fclose($output);
    exit;
}

// ---------------------------------------------------------------------------
// On-screen performance summary
// ---------------------------------------------------------------------------
$sql = "SELECT e.id, e.title, e.course_code, e.course_name, e.pass_mark,
               COUNT(a.id) AS attempts,
               SUM(CASE WHEN a.pass_fail = 'pass' THEN 1 ELSE 0 END) AS passes,
               SUM(CASE WHEN a.pass_fail = 'fail' THEN 1 ELSE 0 END) AS fails,
               AVG(a.percentage) AS avg_percentage,
               MAX(a.percentage) AS max_percentage,
               MIN(a.percentage) AS min_percentage
        FROM examinations e
        LEFT JOIN exam_attempts a ON a.examination_id = e.id AND a.status = 'completed'
        " . ($examFilter > 0 ? 'WHERE e.id = ?' : '') . "
        GROUP BY e.id
        ORDER BY e.title ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($examFilter > 0 ? [$examFilter] : []);
$performance = $stmt->fetchAll();

$page_title = 'Reports';
$active = 'reports';
require __DIR__ . '/../includes/header.php';
?>
<div class="row">
    <div class="col-lg-3 mb-4">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    </div>

    <div class="col-lg-9">
        <h1 class="h3 mb-3">Reports</h1>

        <div class="d-none d-print-block mb-3">
            <h2 class="h4">Examination Performance Report</h2>
            <div class="small text-muted">
                Generated on <?php echo e(date('d M Y, H:i')); ?>
                <?php if ($examFilter > 0): ?>
                    &middot; Filtered by examination
                    <?php foreach ($examinations as $exam): ?>
                        <?php if ((int) $exam['id'] === $examFilter): ?>
                            (<?php echo e($exam['course_code'] . ' - ' . $exam['title']); ?>)
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    &middot; All examinations
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm mb-4 no-print">
            <div class="card-body">
                <form class="row g-2 align-items-end" method="get" action="<?php echo url('admin/reports.php'); ?>">
                    <div class="col-md-6">
                        <label class="form-label">Filter by examination</label>
                        <select class="form-select" name="exam">
                            <option value="0">All examinations</option>
                            <?php foreach ($examinations as $exam): ?>
                                <option value="<?php echo (int) $exam['id']; ?>" <?php echo $examFilter === (int) $exam['id'] ? 'selected' : ''; ?>>
                                    <?php echo e($exam['course_code'] . ' - ' . $exam['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex gap-2 flex-wrap">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
                        <a class="btn btn-success" href="<?php echo url('admin/reports.php?export=csv' . ($examFilter > 0 ? '&exam=' . $examFilter : '')); ?>">
                            <i class="bi bi-filetype-csv me-1"></i>Export CSV
                        </a>
                        <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i>Print
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Examination Performance</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Examination</th>
                            <th class="text-center">Attempts</th>
                            <th class="text-center">Pass</th>
                            <th class="text-center">Fail</th>
                            <th class="text-center">Pass rate</th>
                            <th class="text-center">Average</th>
                            <th class="text-center">Highest</th>
                            <th class="text-center">Lowest</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$performance): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No data available.</td></tr>
                    <?php else: foreach ($performance as $row):
                        $attempts = (int) $row['attempts'];
                        $passRate = $attempts > 0 ? round(((int) $row['passes'] / $attempts) * 100, 1) : 0;
                    ?>
                        <tr>
                            <td><?php echo e($row['course_code']); ?><br><small class="text-muted"><?php echo e($row['title']); ?></small></td>
                            <td class="text-center"><?php echo $attempts; ?></td>
                            <td class="text-center text-success"><?php echo (int) $row['passes']; ?></td>
                            <td class="text-center text-danger"><?php echo (int) $row['fails']; ?></td>
                            <td class="text-center">
                                <?php if ($attempts > 0): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height: 8px;">
                                            <div class="progress-bar <?php echo $passRate >= 70 ? 'bg-success' : ($passRate >= 40 ? 'bg-warning' : 'bg-danger'); ?>"
                                                 style="width: <?php echo e($passRate); ?>%"></div>
                                        </div>
                                        <span class="small text-nowrap"><?php echo e($passRate); ?>%</span>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php echo $row['attempts'] > 0 ? e(round((float) $row['avg_percentage'], 2)) . '%' : '-'; ?>
                            </td>
                            <td class="text-center">
                                <?php echo $row['attempts'] > 0 ? e($row['max_percentage']) . '%' : '-'; ?>
                            </td>
                            <td class="text-center">
                                <?php echo $row['attempts'] > 0 ? e($row['min_percentage']) . '%' : '-'; ?>
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
