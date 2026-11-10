<?php
/**
 * Administrator: assign an examination to specific students.
 * If no students are assigned, the examination stays available to all
 * active students (existing behaviour, preserved).
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pdo = db();
$examId = (int) input('exam');

$stmt = $pdo->prepare('SELECT * FROM examinations WHERE id = ?');
$stmt->execute([$examId]);
$exam = $stmt->fetch();
if (!$exam) {
    set_flash('danger', 'Examination not found.');
    redirect('admin/examinations.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        set_flash('danger', 'Your session expired. Please try again.');
        redirect('admin/assign_exam.php?exam=' . $examId);
    }
    $ids = isset($_POST['student_ids']) && is_array($_POST['student_ids']) ? array_map('intval', $_POST['student_ids']) : [];
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM exam_assignments WHERE examination_id = ?')->execute([$examId]);
        $ins = $pdo->prepare('INSERT INTO exam_assignments (examination_id, student_id) VALUES (?, ?)');
        foreach ($ids as $sid) {
            if ($sid > 0) { $ins->execute([$examId, $sid]); }
        }
        $pdo->commit();
        audit_log('exam.assign', 'examination', $examId, count($ids) . ' student(s) assigned');
        set_flash('success', count($ids) . ' student(s) assigned.' . (count($ids) === 0 ? ' The examination is now available to all students again.' : ''));
    } catch (Exception $e) {
        $pdo->rollBack();
        set_flash('danger', 'Could not save assignments. Please try again.');
    }
    redirect('admin/assign_exam.php?exam=' . $examId);
}

$assigned = [];
foreach ($pdo->query('SELECT student_id FROM exam_assignments WHERE examination_id = ' . (int) $examId) as $r) {
    $assigned[(int) $r['student_id']] = true;
}
$students = $pdo->query('SELECT id, reg_number, full_name, email, status FROM students ORDER BY full_name ASC')->fetchAll();
$search = input('search');

$page_title = 'Assign Examination to Students';
$active = 'examinations';
require __DIR__ . '/../includes/header.php';
?>
<div class="row">
    <div class="col-lg-3 mb-4">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    </div>
    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h1 class="h3 mb-0">Assign Examination</h1>
            <a href="<?php echo url('admin/examinations.php'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to examinations</a>
        </div>
        <div class="alert alert-info">
            <strong><?php echo e($exam['course_code'] . ' - ' . $exam['title']); ?></strong><br>
            Tick the students who may take this examination, then save. Leave all unticked to make it available to all students.
            Currently assigned: <strong><?php echo count($assigned); ?></strong>
        </div>
        <form class="row g-2 mb-3" method="get" action="<?php echo url('admin/assign_exam.php'); ?>">
            <input type="hidden" name="exam" value="<?php echo (int) $examId; ?>">
            <div class="col-sm-9"><input type="text" class="form-control" name="search" value="<?php echo e($search); ?>" placeholder="Search by name, reg number or email"></div>
            <div class="col-sm-3"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search me-1"></i>Search</button></div>
        </form>
        <form method="post" action="<?php echo url('admin/assign_exam.php?exam=' . (int) $examId); ?>">
            <?php echo csrf_field(); ?>
            <div class="card shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th style="width:50px"></th><th>Reg. No.</th><th>Name</th><th>Email</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php $shown = 0; foreach ($students as $s):
                            if ($search !== '' && stripos($s['full_name'] . ' ' . $s['reg_number'] . ' ' . $s['email'], $search) === false) { continue; }
                            $shown++;
                        ?>
                            <tr>
                                <td><input class="form-check-input" type="checkbox" name="student_ids[]" value="<?php echo (int) $s['id']; ?>" <?php echo isset($assigned[(int) $s['id']]) ? 'checked' : ''; ?>></td>
                                <td><?php echo e($s['reg_number']); ?></td>
                                <td><?php echo e($s['full_name']); ?></td>
                                <td><?php echo e($s['email']); ?></td>
                                <td><?php echo $s['status'] === 'inactive' ? '<span class="badge bg-secondary">Inactive</span>' : '<span class="badge bg-success">Active</span>'; ?></td>
                            </tr>
                        <?php endforeach; if (!$shown): ?><tr><td colspan="5" class="text-center text-muted py-4">No students found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save assignments</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
