<?php
/**
 * Administrator: manage registered students.
 * Supports search, pagination and deletion.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pdo = db();

require_once __DIR__ . '/../includes/csv.php';

// Bulk-import template (must run before any HTML output).
if (input('template') === 'students') {
    csv_send('students_template.csv', [
        ['reg_number', 'full_name', 'email', 'department', 'password'],
        ['STU100', 'Ada Lovelace', 'ada@example.com', 'Computer Science', 'Pass1234'],
        ['STU101', 'Alan Turing', 'alan@example.com', 'Mathematics', ''],
    ]);
}

// Export student records (must run before any HTML output).
if (input('export') === 'students') {
    $rows = $pdo->query(
        'SELECT reg_number, full_name, email, department, status, created_at FROM students ORDER BY full_name ASC'
    )->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Reg Number', 'Full Name', 'Email', 'Department', 'Status', 'Registered']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['reg_number'], $r['full_name'], $r['email'], $r['department'], $r['status'], $r['created_at']]);
    }
    fclose($out);
    exit;
}

// Handle bulk student import.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('action') === 'import') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        set_flash('danger', 'Your session expired. Please try again.');
        redirect('admin/students.php');
    }

    $readError = null;
    $rows = csv_read_upload('csv_file', $readError);
    if ($rows === null) {
        set_flash('danger', 'Import failed: ' . $readError);
        redirect('admin/students.php');
    }

    // Preload existing identities so duplicates are rejected quickly.
    $taken = [];
    foreach ($pdo->query('SELECT email, reg_number FROM students') as $r) {
        $taken[strtolower($r['email'])]      = true;
        $taken[strtolower($r['reg_number'])] = true;
    }

    $insert = $pdo->prepare(
        'INSERT INTO students (reg_number, full_name, email, password, department)
         VALUES (?, ?, ?, ?, ?)'
    );

    $imported  = 0;
    $problems  = [];
    $generated = [];
    $rowNo     = 0;

    foreach ($rows as $row) {
        $rowNo++;
        while (count($row) < 5) { $row[] = ''; } // pad: reg, name, email, dept, password
        list($reg, $name, $email, $dept, $pass) = $row;
        $reg = normalize_reg_number($reg);
        $email = strtolower(trim($email));

        if ($name === '') {
            $problems[] = 'Data row ' . $rowNo . ': full_name is required.';
            continue;
        }
        $regErrs = reg_number_errors($reg);
        if ($regErrs) {
            $problems[] = 'Data row ' . $rowNo . ': ' . $regErrs[0];
            continue;
        }
        if (!is_valid_email($email)) {
            $problems[] = 'Data row ' . $rowNo . ': invalid email "' . $email . '".';
            continue;
        }
        if (isset($taken[strtolower($reg)]) || isset($taken[$email])) {
            $problems[] = 'Data row ' . $rowNo . ': duplicate reg_number or email (' . $reg . ').';
            continue;
        }
        if ($pass === '') {
            $pass = generate_temp_password(10);
            $generated[] = $reg . ' = ' . $pass;
        } else {
            $policyErrors = password_policy_errors($pass);
            if ($policyErrors) {
                $problems[] = 'Data row ' . $rowNo . ': ' . $policyErrors[0];
                continue;
            }
        }

        $insert->execute([$reg, $name, $email, password_hash($pass, PASSWORD_DEFAULT),
                          $dept !== '' ? $dept : null]);
        $taken[strtolower($reg)] = true;
        $taken[$email]           = true;
        $imported++;
    }

    if ($imported > 0) {
        audit_log('student.import', 'student', null, $imported . ' imported');
    }

    if ($problems) {
        set_flash('warning', $imported . ' student(s) imported, ' . count($problems) . ' row(s) skipped.');
        set_flash('info', implode('  ', array_slice($problems, 0, 10))
            . (count($problems) > 10 ? '  (+' . (count($problems) - 10) . ' more)' : ''));
    } else {
        set_flash('success', $imported . ' student(s) imported successfully.');
    }
    if ($generated) {
        set_flash('info', 'Auto-generated passwords: ' . implode(', ', array_slice($generated, 0, 20))
            . (count($generated) > 20 ? ' (+' . (count($generated) - 20) . ' more)' : ''));
    }
    redirect('admin/students.php');
}

// Add / edit a student (admin assigns the official registration number).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('action') === 'save') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        set_flash('danger', 'Your session expired. Please try again.');
        redirect('admin/students.php');
    }
    $id     = (int) input('id');
    $reg    = normalize_reg_number(input('reg_number'));
    $name   = input('full_name');
    $email  = strtolower(input('email'));
    $dept   = input('department');
    $status = input('status', 'active') === 'inactive' ? 'inactive' : 'active';
    $pass   = isset($_POST['password']) ? trim((string) $_POST['password']) : '';
    $formErrors = [];
    foreach (reg_number_errors($reg) as $e) { $formErrors[] = $e; }
    if ($name === '') { $formErrors[] = 'Full name is required.'; }
    if (!is_valid_email($email)) { $formErrors[] = 'A valid email is required.'; }
    if ($id === 0 && $pass !== '') {
        foreach (password_policy_errors($pass) as $e) { $formErrors[] = $e; }
    }
    if (!$formErrors) {
        $check = $pdo->prepare('SELECT id FROM students WHERE (LOWER(reg_number) = ? OR LOWER(email) = ?) AND id <> ? LIMIT 1');
        $check->execute([strtolower($reg), $email, $id]);
        if ($check->fetch()) {
            $formErrors[] = 'Another student already uses that registration number or email.';
        }
    }
    if ($formErrors) {
        set_flash('danger', implode(' ', $formErrors));
        redirect('admin/students.php' . ($id > 0 ? '?edit=' . $id : ''));
    }
    if ($id > 0) {
        $pdo->prepare('UPDATE students SET reg_number = ?, full_name = ?, email = ?, department = ?, status = ? WHERE id = ?')
            ->execute([$reg, $name, $email, $dept !== '' ? $dept : null, $status, $id]);
        audit_log('student.update', 'student', $id, $reg);
        set_flash('success', 'Student updated.');
    } else {
        $tempGenerated = false;
        if ($pass === '') { $pass = generate_temp_password(10); $tempGenerated = true; }
        $pdo->prepare('INSERT INTO students (reg_number, full_name, email, password, department, status) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$reg, $name, $email, password_hash($pass, PASSWORD_DEFAULT), $dept !== '' ? $dept : null, $status]);
        audit_log('student.create', 'student', (int) $pdo->lastInsertId(), $reg);
        set_flash('success', 'Student added.' . ($tempGenerated ? ' Temporary password: ' . $pass . ' (shown once).' : ''));
    }
    redirect('admin/students.php');
}

// Activate / deactivate a student account.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('action') === 'toggle') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        set_flash('danger', 'Your session expired. Please try again.');
    } else {
        $id = (int) input('id');
        if ($id > 0) {
            $pdo->prepare("UPDATE students SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?")->execute([$id]);
            audit_log('student.toggle', 'student', $id);
            set_flash('success', 'Student account status updated.');
        }
    }
    redirect('admin/students.php');
}

// Handle password reset (issues a random temporary password, shown once).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('action') === 'reset_password') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        set_flash('danger', 'Your session expired. Please try again.');
    } else {
        $id = (int) input('id');
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT reg_number FROM students WHERE id = ?');
            $stmt->execute([$id]);
            $student = $stmt->fetch();
            if ($student) {
                $temp = generate_temp_password(10);
                $pdo->prepare('UPDATE students SET password = ? WHERE id = ?')
                    ->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);
                audit_log('student.reset_password', 'student', $id);
                set_flash('success', 'Temporary password for ' . $student['reg_number']
                    . ': ' . $temp . '  (shown once - share it securely).');
            }
        }
    }
    redirect('admin/students.php');
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('action') === 'delete') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        set_flash('danger', 'Your session expired. Please try again.');
    } else {
        $id = (int) input('id');
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM students WHERE id = ?');
            $stmt->execute([$id]);
            audit_log('student.delete', 'student', $id);
            set_flash('success', 'Student account deleted.');
        }
    }
    redirect('admin/students.php');
}

$editingStudent = null;
$editStudentId = (int) input('edit');
if ($editStudentId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM students WHERE id = ?');
    $stmt->execute([$editStudentId]);
    $editingStudent = $stmt->fetch();
}

$search = input('search');
$page   = max(1, (int) input('page', '1'));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where  = '';
$params = [];
if ($search !== '') {
    $where = 'WHERE full_name LIKE ? OR email LIKE ? OR reg_number LIKE ?';
    $like  = '%' . $search . '%';
    $params = [$like, $like, $like];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM students {$where}");
$countStmt->execute($params);
$totalRows  = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$stmt = $pdo->prepare(
    "SELECT s.*,
            (SELECT COUNT(*) FROM exam_attempts a WHERE a.student_id = s.id) AS attempt_count
     FROM students s
     {$where}
     ORDER BY s.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$students = $stmt->fetchAll();

$page_title = 'Manage Students';
$active = 'students';
require __DIR__ . '/../includes/header.php';
?>
<div class="row">
    <div class="col-lg-3 mb-4">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    </div>

    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h1 class="h3 mb-0">Students</h1>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-success btn-sm" href="<?php echo url('admin/students.php?export=students'); ?>"><i class="bi bi-download me-1"></i>Export</a>
                <span class="text-muted align-self-center"><?php echo $totalRows; ?> registered</span>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><?php echo $editingStudent ? 'Edit Student' : 'Add Student (assign registration number)'; ?></div>
            <div class="card-body">
                <form method="post" action="<?php echo url('admin/students.php'); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo $editingStudent ? (int) $editingStudent['id'] : ''; ?>">
                    <div class="row g-2">
                        <div class="col-md-4"><label class="form-label">Registration number</label>
                            <input type="text" class="form-control" name="reg_number" required value="<?php echo e($editingStudent ? $editingStudent['reg_number'] : ''); ?>">
                            <div class="form-text"><?php echo e(reg_number_hint()); ?></div></div>
                        <div class="col-md-4"><label class="form-label">Full name</label>
                            <input type="text" class="form-control" name="full_name" required value="<?php echo e($editingStudent ? $editingStudent['full_name'] : ''); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" required value="<?php echo e($editingStudent ? $editingStudent['email'] : ''); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Department</label>
                            <input type="text" class="form-control" name="department" value="<?php echo e($editingStudent ? $editingStudent['department'] : ''); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="active" <?php echo (!$editingStudent || $editingStudent['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($editingStudent && $editingStudent['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive (cannot log in)</option>
                            </select></div>
                        <?php if (!$editingStudent): ?>
                        <div class="col-md-4"><label class="form-label">Password (blank = auto-generate)</label>
                            <input type="text" class="form-control" name="password" placeholder="Leave blank to auto-generate">
                            <div class="form-text"><?php echo e(password_policy_hint()); ?></div></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($editingStudent): ?>
                        <?php $c = (int) $pdo->query('SELECT COUNT(*) FROM exam_attempts WHERE student_id=' . (int) $editingStudent['id'])->fetchColumn(); ?>
                        <div class="small text-muted mt-2">Attempts: <?php echo $c; ?> &middot; <a href="<?php echo url('admin/results.php?search=' . urlencode($editingStudent['reg_number'])); ?>">View results</a></div>
                    <?php endif; ?>
                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i><?php echo $editingStudent ? 'Update student' : 'Add student'; ?></button>
                        <?php if ($editingStudent): ?><a href="<?php echo url('admin/students.php'); ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <form class="row g-2 mb-3" method="get" action="<?php echo url('admin/students.php'); ?>">
            <div class="col-sm-8">
                <input type="text" class="form-control" name="search"
                       value="<?php echo e($search); ?>" placeholder="Search by name, email or registration number">
            </div>
            <div class="col-sm-4 d-flex gap-2">
                <button class="btn btn-primary flex-grow-1" type="submit"><i class="bi bi-search me-1"></i>Search</button>
                <?php if ($search !== ''): ?>
                    <a class="btn btn-outline-secondary" href="<?php echo url('admin/students.php'); ?>">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold" data-bs-toggle="collapse"
                 data-bs-target="#importStudents" role="button" aria-expanded="false">
                <i class="bi bi-upload me-1"></i>Import students from CSV
                <i class="bi bi-chevron-down float-end small"></i>
            </div>
            <div class="collapse" id="importStudents">
                <div class="card-body">
                    <p class="small text-muted">
                        Upload a CSV with the columns
                        <code>reg_number, full_name, email, department, password</code>.
                        Leave <code>password</code> blank to auto-generate one (shown once after import).
                        A supplied password must follow the policy: <?php echo e(password_policy_hint()); ?>
                        <a href="<?php echo url('admin/students.php?template=students'); ?>">Download a template</a>.
                    </p>
                    <form method="post" enctype="multipart/form-data" action="<?php echo url('admin/students.php'); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="import">
                        <div class="row g-2 align-items-end">
                            <div class="col-sm-8">
                                <label class="form-label">CSV file</label>
                                <input type="file" class="form-control" name="csv_file" accept=".csv,text/csv" required>
                            </div>
                            <div class="col-sm-4">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-upload me-1"></i>Import
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Reg. No.</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Department</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Attempts</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$students): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No students found.</td></tr>
                    <?php else: $n = $offset + 1; foreach ($students as $s): ?>
                        <tr>
                            <td><?php echo $n++; ?></td>
                            <td><?php echo e($s['reg_number']); ?></td>
                            <td><?php echo e($s['full_name']); ?></td>
                            <td><?php echo e($s['email']); ?></td>
                            <td><?php echo e($s['department'] ? $s['department'] : '-'); ?></td>
                            <td class="text-center"><?php echo ($s['status'] === 'inactive') ? '<span class="badge bg-secondary">Inactive</span>' : '<span class="badge bg-success">Active</span>'; ?></td>
                            <td class="text-center"><?php echo (int) $s['attempt_count']; ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo url('admin/students.php?edit=' . (int) $s['id']); ?>" title="Edit / view profile"><i class="bi bi-pencil"></i></a>
                                <form method="post" action="<?php echo url('admin/students.php'); ?>" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-info" data-confirm="Activate/deactivate this student account?" title="Activate / deactivate"><i class="bi bi-toggle-<?php echo $s['status'] === 'active' ? 'on' : 'off'; ?>"></i></button>
                                </form>
                                <form method="post" action="<?php echo url('admin/students.php'); ?>" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning"
                                            data-confirm="Reset this student's password to a random temporary one?">
                                        <i class="bi bi-key"></i>
                                    </button>
                                </form>
                                <form method="post" action="<?php echo url('admin/students.php'); ?>" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                            data-confirm="Delete this student and all their examination records?">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-white">
                <nav>
                    <ul class="pagination mb-0 justify-content-center">
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo url('admin/students.php?page=' . $p . ($search !== '' ? '&search=' . urlencode($search) : '')); ?>"><?php echo $p; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
