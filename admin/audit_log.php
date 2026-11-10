<?php
/**
 * Administrator: security audit trail - recorded admin actions and recent
 * authentication activity (successful and failed logins).
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pdo = db();

$auditSearch = input('search');
if ($auditSearch !== '') {
    $stmt = $pdo->prepare(
        'SELECT al.*, a.full_name AS admin_name
         FROM audit_logs al
         LEFT JOIN admins a ON a.id = al.admin_id
         WHERE al.action LIKE ? OR al.details LIKE ? OR a.full_name LIKE ?
         ORDER BY al.id DESC LIMIT 200'
    );
    $like = '%' . $auditSearch . '%';
    $stmt->execute([$like, $like, $like]);
    $logs = $stmt->fetchAll();
} else {
    $logs = $pdo->query(
        'SELECT al.*, a.full_name AS admin_name
         FROM audit_logs al
         LEFT JOIN admins a ON a.id = al.admin_id
         ORDER BY al.id DESC
         LIMIT 200'
    )->fetchAll();
}

$logins = $pdo->query(
    'SELECT email, role, ip_address, success, attempted_at
     FROM login_attempts
     ORDER BY id DESC
     LIMIT 50'
)->fetchAll();

$failed24h = (int) $pdo->query(
    'SELECT COUNT(*) FROM login_attempts
     WHERE success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 DAY)'
)->fetchColumn();

$page_title = 'Audit Log';
$active = 'audit';
require __DIR__ . '/../includes/header.php';
?>
<div class="row">
    <div class="col-lg-3 mb-4">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    </div>

    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h1 class="h3 mb-0">Security Audit Log</h1>
            <span class="badge bg-<?php echo $failed24h > 0 ? 'warning text-dark' : 'success'; ?>">
                <?php echo $failed24h; ?> failed login(s) in last 24h
            </span>
        </div>

        <form class="row g-2 mb-3" method="get" action="<?php echo url('admin/audit_log.php'); ?>">
            <div class="col-sm-9"><input type="text" class="form-control" name="search" value="<?php echo e($auditSearch); ?>" placeholder="Search actions, details or admin name"></div>
            <div class="col-sm-3"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search me-1"></i>Search</button></div>
        </form>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-journal-text me-1"></i>Administrator Actions
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>When</th>
                            <th>Admin</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>Details</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$logs): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No administrator actions recorded yet.</td></tr>
                    <?php else: foreach ($logs as $log): ?>
                        <tr>
                            <td class="small text-muted text-nowrap"><?php echo e($log['created_at']); ?></td>
                            <td><?php echo e($log['admin_name'] ? $log['admin_name'] : 'System'); ?></td>
                            <td><span class="badge bg-secondary"><?php echo e($log['action']); ?></span></td>
                            <td class="small"><?php echo e($log['entity_type']); ?><?php echo $log['entity_id'] ? ' #' . (int) $log['entity_id'] : ''; ?></td>
                            <td class="small text-muted"><?php echo $log['details'] ? e($log['details']) : '-'; ?></td>
                            <td class="small text-muted"><?php echo $log['ip_address'] ? e($log['ip_address']) : '-'; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-box-arrow-in-right me-1"></i>Recent Login Activity
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>When</th>
                            <th>Account</th>
                            <th>Role</th>
                            <th>Result</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$logins): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No login activity recorded yet.</td></tr>
                    <?php else: foreach ($logins as $l): ?>
                        <tr>
                            <td class="small text-muted text-nowrap"><?php echo e($l['attempted_at']); ?></td>
                            <td class="small"><?php echo e($l['email'] ? $l['email'] : '-'); ?></td>
                            <td class="small text-muted"><?php echo e($l['role']); ?></td>
                            <td>
                                <?php if ((int) $l['success'] === 1): ?>
                                    <span class="badge bg-success">Success</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Failed</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?php echo $l['ip_address'] ? e($l['ip_address']) : '-'; ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
