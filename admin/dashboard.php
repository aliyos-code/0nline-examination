<?php
/**
 * Administrator dashboard with live statistics from the database.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pdo = db();
auto_close_expired_exams($pdo);

$totalStudents   = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
$totalAdmins     = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
$totalExams      = (int) $pdo->query('SELECT COUNT(*) FROM examinations')->fetchColumn();
$totalQuestions  = (int) $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn();
$totalAttempts   = (int) $pdo->query('SELECT COUNT(*) FROM exam_attempts')->fetchColumn();
$completed       = (int) $pdo->query("SELECT COUNT(*) FROM exam_attempts WHERE status = 'completed'")->fetchColumn();
// Same display status as students see (single source of truth).
$allExamsForStatus = $pdo->query('SELECT status, available_from, available_until FROM examinations')->fetchAll();
$activeExams = 0; $upcomingExams = 0; $closedExams = 0;
foreach ($allExamsForStatus as $ex) {
    $ds = exam_display_status($ex);
    if ($ds === 'OPEN') { $activeExams++; }
    elseif ($ds === 'NOT YET OPEN') { $upcomingExams++; }
    else { $closedExams++; }
}
$recentStudents  = $pdo->query('SELECT id, reg_number, full_name, email, created_at FROM students ORDER BY created_at DESC LIMIT 5')->fetchAll();
$recentActivity  = $pdo->query('SELECT action, entity_type, entity_id, details, created_at FROM audit_logs ORDER BY created_at DESC LIMIT 5')->fetchAll();
$notifications   = $pdo->query("SELECT title, body, created_at FROM announcements ORDER BY created_at DESC LIMIT 3")->fetchAll();

$recentExams = $pdo->query(
    'SELECT e.id, e.title, e.course_code, e.status, e.available_from, e.available_until, e.created_at,
            (SELECT COUNT(*) FROM questions q WHERE q.examination_id = e.id) AS question_count
     FROM examinations e
     ORDER BY e.created_at DESC
     LIMIT 5'
)->fetchAll();

$recentResults = $pdo->query(
    'SELECT a.percentage, a.pass_fail, a.end_time,
            s.full_name, s.reg_number,
            e.title
     FROM exam_attempts a
     JOIN students s ON s.id = a.student_id
     JOIN examinations e ON e.id = a.examination_id
     WHERE a.status = \'completed\'
     ORDER BY a.end_time DESC
     LIMIT 5'
)->fetchAll();

// ---------------------------------------------------------------------------
// Analytics: pass rate per examination
// ---------------------------------------------------------------------------
$examStats = $pdo->query(
    'SELECT e.id, e.title, e.course_code, e.pass_mark,
            COUNT(a.id) AS attempts,
            SUM(CASE WHEN a.pass_fail = \'pass\' THEN 1 ELSE 0 END) AS passes,
            ROUND(AVG(a.percentage), 1) AS avg_percentage,
            MAX(a.percentage) AS best_percentage,
            MIN(a.percentage) AS worst_percentage
     FROM examinations e
     LEFT JOIN exam_attempts a
            ON a.examination_id = e.id AND a.status = \'completed\'
     GROUP BY e.id, e.title, e.course_code, e.pass_mark
     ORDER BY attempts DESC, e.title ASC'
)->fetchAll();

$totalCompleted = 0;
$totalPassed    = 0;
$percentageSum  = 0.0;
foreach ($examStats as $stat) {
    $totalCompleted += (int) $stat['attempts'];
    $totalPassed    += (int) $stat['passes'];
    if ((int) $stat['attempts'] > 0) {
        $percentageSum += (float) $stat['avg_percentage'] * (int) $stat['attempts'];
    }
}
$overallPassRate = $totalCompleted > 0 ? round(($totalPassed / $totalCompleted) * 100, 1) : 0.0;
$overallAverage  = $totalCompleted > 0 ? round($percentageSum / $totalCompleted, 1) : 0.0;

$page_title = 'Admin Dashboard';
$active = 'dashboard';
$page_theme = 'admin';

// Admin profile (photo + name) for the hero banner.
require_once __DIR__ . '/../includes/profile_picture.php';
$adminId = (int) current_user()['id'];
$stmt = $pdo->prepare('SELECT full_name, email, picture, created_at FROM admins WHERE id = ?');
$stmt->execute(array($adminId));
$adminProfile = $stmt->fetch();
$adminPicUrl = ($adminProfile && isset($adminProfile['picture']) && $adminProfile['picture'] !== '')
    ? profile_pic_url('admin', $adminProfile['picture']) : null;
$adminInitials = $adminProfile ? profile_pic_initials($adminProfile['full_name']) : 'A';

require __DIR__ . '/../includes/header.php';
?>
<div class="row">
    <div class="col-lg-3 mb-4">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    </div>

    <div class="col-lg-9">
        <div class="dash-hero dash-hero-admin mb-4">
            <div class="d-flex align-items-center gap-3">
                <?php if ($adminPicUrl): ?>
                    <img src="<?php echo e($adminPicUrl); ?>" alt="Admin profile photo" class="profile-photo" style="width:72px;height:72px;">
                <?php else: ?>
                    <span class="profile-photo-fallback" style="width:72px;height:72px;font-size:1.4rem;"><?php echo e($adminInitials); ?></span>
                <?php endif; ?>
                <?php echo admin_logo('lg'); ?>
                <div>
                    <div class="dash-hero-name">Admin: <?php echo e($adminProfile ? $adminProfile['full_name'] : 'Aliyu Abdussalam Abdullah'); ?></div>
                    <div class="dash-hero-sub">A + A<sup>2</sup> &middot; Administrator &middot; <?php echo e($adminProfile['email']); ?></div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?php echo url('admin/examinations.php'); ?>" class="btn btn-warning btn-sm fw-semibold shadow-sm">
                    <i class="bi bi-journal-plus me-1"></i>New Examination
                </a>
                <a href="<?php echo url('admin/ai_exam.php'); ?>" class="btn btn-light btn-sm fw-semibold shadow-sm">
                    <i class="bi bi-robot me-1"></i>AI Generator
                </a>
            </div>
        </div>

        <h1 class="h3 mb-4">Dashboard</h1>

        <div class="d-flex flex-wrap gap-2 mb-4 student-actions">
            <a href="<?php echo url('admin/examinations.php'); ?>" class="btn btn-primary btn-sm"><i class="bi bi-journal-text me-1"></i>Examination Management</a>
            <a href="<?php echo url('admin/questions.php'); ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-patch-question me-1"></i>Question Management</a>
            <a href="<?php echo url('admin/students.php'); ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-people me-1"></i>Student Management</a>
            <a href="<?php echo url('admin/results.php'); ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-clipboard-data me-1"></i>Results</a>
            <a href="<?php echo url('admin/profile.php'); ?>" class="btn btn-outline-warning btn-sm"><i class="bi bi-camera me-1"></i>Profile Photo</a>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="card stat-card bg-brand h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-value"><?php echo $totalStudents; ?></div>
                            <div class="small">Students</div>
                        </div>
                        <i class="bi bi-people stat-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card stat-card bg-teal h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-value"><?php echo $totalExams; ?></div>
                            <div class="small">Examinations</div>
                        </div>
                        <i class="bi bi-journal-text stat-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card stat-card bg-amber h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-value"><?php echo $totalQuestions; ?></div>
                            <div class="small">Questions</div>
                        </div>
                        <i class="bi bi-patch-question stat-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card stat-card bg-purple h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-value"><?php echo $totalAttempts; ?></div>
                            <div class="small">Attempts</div>
                        </div>
                        <i class="bi bi-pencil-square stat-icon"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-6 col-xl-3">
                <div class="card stat-card bg-slate h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-value"><?php echo $completed; ?></div>
                            <div class="small">Completed Attempts</div>
                        </div>
                        <i class="bi bi-check2-circle stat-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card stat-card bg-rose h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-value"><?php echo $activeExams; ?></div>
                            <div class="small">OPEN Examinations</div>
                        </div>
                        <i class="bi bi-broadcast stat-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card stat-card bg-teal h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-value"><?php echo $upcomingExams; ?></div>
                            <div class="small">NOT YET OPEN</div>
                        </div>
                        <i class="bi bi-calendar-week stat-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-6 col-xl-3">
                <div class="card stat-card bg-amber h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-value"><?php echo $totalAdmins; ?></div>
                            <div class="small">Administrators</div>
                        </div>
                        <i class="bi bi-shield-lock stat-icon"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-bell me-1"></i>Notifications</div>
                    <div class="list-group list-group-flush">
                        <?php if (!$notifications): ?><div class="list-group-item text-muted small">No announcements yet.</div>
                        <?php else: foreach ($notifications as $n): ?>
                            <div class="list-group-item"><div class="fw-semibold small"><?php echo e($n['title']); ?></div><div class="small text-muted"><?php echo e($n['created_at']); ?></div></div>
                        <?php endforeach; endif; ?>
                    </div>
                    <div class="card-footer bg-white"><a href="<?php echo url('admin/announcements.php'); ?>" class="btn btn-sm btn-outline-primary">Manage announcements</a> <a href="<?php echo url('admin/settings.php'); ?>" class="btn btn-sm btn-outline-secondary">System settings</a></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-person-plus me-1"></i>Recent Registrations</div>
                    <div class="table-responsive"><table class="table table-hover mb-0 align-middle"><tbody>
                        <?php if (!$recentStudents): ?><tr><td class="text-center text-muted py-4">No students yet.</td></tr>
                        <?php else: foreach ($recentStudents as $s): ?><tr><td><?php echo e($s['full_name']); ?><br><small class="text-muted"><?php echo e($s['reg_number']); ?> &middot; <?php echo e($s['created_at']); ?></small></td></tr><?php endforeach; endif; ?>
                    </tbody></table></div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-activity me-1"></i>Recent Activity</div>
                    <div class="table-responsive"><table class="table table-hover mb-0 align-middle"><tbody>
                        <?php if (!$recentActivity): ?><tr><td class="text-center text-muted py-4">No activity yet.</td></tr>
                        <?php else: foreach ($recentActivity as $a): ?><tr><td><span class="badge bg-secondary"><?php echo e($a['action']); ?></span><br><small class="text-muted"><?php echo e($a['created_at']); ?></small></td></tr><?php endforeach; endif; ?>
                    </tbody></table></div>
                    <div class="card-footer bg-white"><a href="<?php echo url('admin/audit_log.php'); ?>" class="btn btn-sm btn-outline-primary">Full audit log</a></div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-graph-up-arrow me-1"></i>Performance Analytics</span>
                <a href="<?php echo url('admin/reports.php'); ?>" class="btn btn-sm btn-outline-primary">Full report</a>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-6 col-md-3">
                        <div class="meta-label">Overall pass rate</div>
                        <div class="h4 mb-0"><?php echo e($overallPassRate); ?>%</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="meta-label">Average score</div>
                        <div class="h4 mb-0"><?php echo e($overallAverage); ?>%</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="meta-label">Passed / attempts</div>
                        <div class="h4 mb-0"><?php echo $totalPassed; ?> / <?php echo $totalCompleted; ?></div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="meta-label">Examinations</div>
                        <div class="h4 mb-0"><?php echo count($examStats); ?></div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Examination</th>
                                <th class="text-center">Attempts</th>
                                <th class="text-center">Pass rate</th>
                                <th class="text-center">Average</th>
                                <th class="text-center">Best / Worst</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$examStats): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No examinations yet.</td></tr>
                        <?php else: foreach ($examStats as $stat):
                            $attempts = (int) $stat['attempts'];
                            $passRate = $attempts > 0 ? round(((int) $stat['passes'] / $attempts) * 100, 1) : 0;
                            $barClass = $passRate >= 70 ? 'bg-success' : ($passRate >= 40 ? 'bg-warning' : 'bg-danger');
                        ?>
                            <tr>
                                <td>
                                    <a href="<?php echo url('admin/questions.php?exam=' . (int) $stat['id']); ?>"><?php echo e($stat['title']); ?></a>
                                    <br><small class="text-muted"><?php echo e($stat['course_code']); ?> &middot; pass mark <?php echo (int) $stat['pass_mark']; ?>%</small>
                                </td>
                                <td class="text-center"><?php echo $attempts; ?></td>
                                <td class="text-center" style="min-width: 150px;">
                                    <?php if ($attempts > 0): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="progress flex-grow-1" style="height: 8px;">
                                                <div class="progress-bar <?php echo $barClass; ?>" style="width: <?php echo e($passRate); ?>%"></div>
                                            </div>
                                            <span class="small text-nowrap"><?php echo e($passRate); ?>%</span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">No attempts</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo $attempts > 0 ? e($stat['avg_percentage']) . '%' : '-'; ?></td>
                                <td class="text-center small text-muted">
                                    <?php echo $attempts > 0 ? e($stat['best_percentage']) . '% / ' . e($stat['worst_percentage']) . '%' : '-'; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold">Recent Examinations</div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Title</th>
                                    <th>Code</th>
                                    <th class="text-center">Questions</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$recentExams): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No examinations yet.</td></tr>
                            <?php else: foreach ($recentExams as $exam): ?>
                                <tr>
                                    <td><a href="<?php echo url('admin/questions.php?exam=' . (int) $exam['id']); ?>"><?php echo e($exam['title']); ?></a></td>
                                    <td><?php echo e($exam['course_code']); ?></td>
                                    <td class="text-center"><?php echo (int) $exam['question_count']; ?></td>
                                    <td><?php echo exam_status_badge($exam); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="card shadow-sm h-100">
                    <div class="card-header bg-white fw-semibold">Recent Results</div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Examination</th>
                                    <th class="text-center">Score</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (!$recentResults): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No results yet.</td></tr>
                            <?php else: foreach ($recentResults as $row): ?>
                                <tr>
                                    <td><?php echo e($row['full_name']); ?><br><small class="text-muted"><?php echo e($row['reg_number']); ?></small></td>
                                    <td><?php echo e($row['title']); ?></td>
                                    <td class="text-center"><?php echo e($row['percentage']); ?>%</td>
                                    <td><?php echo pass_fail_badge($row['pass_fail']); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
