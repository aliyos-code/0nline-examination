<?php
/**
 * Administrator: post and manage announcements (notices) for students.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pdo    = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        set_flash('danger', 'Your session expired. Please try again.');
        redirect('admin/announcements.php');
    }

    $action = input('action');

    if ($action === 'delete') {
        $id = (int) input('id');
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM announcements WHERE id = ?');
            $stmt->execute([$id]);
            audit_log('announcement.delete', 'announcement', $id);
            set_flash('success', 'Announcement deleted.');
        }
        redirect('admin/announcements.php');
    }

    if ($action === 'save') {
        $id       = (int) input('id');
        $title    = input('title');
        $body     = isset($_POST['body']) ? trim((string) $_POST['body']) : '';
        $audience = input('audience', 'students');
        if ($audience !== 'all' && $audience !== 'students') {
            $audience = 'students';
        }

        if ($title === '') { $errors[] = 'A title is required.'; }
        if ($body === '')  { $errors[] = 'The announcement text is required.'; }

        if (!$errors) {
            if ($id > 0) {
                $stmt = $pdo->prepare('UPDATE announcements SET title = ?, body = ?, audience = ? WHERE id = ?');
                $stmt->execute([$title, $body, $audience, $id]);
                audit_log('announcement.update', 'announcement', $id, $title);
                set_flash('success', 'Announcement updated.');
            } else {
                $stmt = $pdo->prepare('INSERT INTO announcements (title, body, audience, created_by) VALUES (?, ?, ?, ?)');
                $stmt->execute([$title, $body, $audience, (int) current_user()['id']]);
                audit_log('announcement.create', 'announcement', (int) $pdo->lastInsertId(), $title);
                set_flash('success', 'Announcement published.');
            }
            redirect('admin/announcements.php');
        }
    }
}

// Editing state
$editing = null;
$editId  = (int) input('edit');
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch();
}

$stmt = $pdo->prepare(
    'SELECT a.*, ad.full_name AS author
     FROM announcements a
     LEFT JOIN admins ad ON ad.id = a.created_by
     ORDER BY a.created_at DESC, a.id DESC'
);
$stmt->execute();
$announcements = $stmt->fetchAll();

$page_title = 'Announcements';
$active = 'announcements';
require __DIR__ . '/../includes/header.php';
?>
<div class="row">
    <div class="col-lg-3 mb-4">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    </div>

    <div class="col-lg-9">
        <h1 class="h3 mb-3"><i class="bi bi-megaphone me-1"></i>Announcements</h1>
        <p class="text-muted">
            Post a notice for your students. Announcements appear on the student
            dashboard as soon as they log in.
        </p>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                <?php echo $editing ? 'Edit Announcement' : 'New Announcement'; ?>
            </div>
            <div class="card-body">
                <form method="post" action="<?php echo url('admin/announcements.php'); ?>" novalidate>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo e($editing ? $editing['id'] : ''); ?>">

                    <div class="mb-3">
                        <label class="form-label">Title</label>
                        <input type="text" class="form-control" name="title" required
                               value="<?php echo e($editing ? $editing['title'] : ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Message</label>
                        <textarea class="form-control" name="body" rows="4" required><?php echo e($editing ? $editing['body'] : ''); ?></textarea>
                    </div>
                    <div class="mb-3" style="max-width: 320px;">
                        <label class="form-label">Audience</label>
                        <select class="form-select" name="audience">
                            <option value="students" <?php echo (!$editing || $editing['audience'] === 'students') ? 'selected' : ''; ?>>Students</option>
                            <option value="all" <?php echo ($editing && $editing['audience'] === 'all') ? 'selected' : ''; ?>>Students and administrators</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i><?php echo $editing ? 'Update' : 'Publish'; ?>
                    </button>
                    <?php if ($editing): ?>
                        <a href="<?php echo url('admin/announcements.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Published announcements</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Title</th>
                            <th>Message</th>
                            <th class="text-center">Audience</th>
                            <th class="text-end">Posted</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$announcements): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No announcements yet.</td></tr>
                    <?php else: foreach ($announcements as $item): ?>
                        <tr>
                            <td><strong><?php echo e($item['title']); ?></strong></td>
                            <td class="small text-muted"><?php echo e(mb_strimwidth($item['body'], 0, 90, '...')); ?></td>
                            <td class="text-center">
                                <span class="badge bg-<?php echo $item['audience'] === 'all' ? 'secondary' : 'primary'; ?>">
                                    <?php echo $item['audience'] === 'all' ? 'All' : 'Students'; ?>
                                </span>
                            </td>
                            <td class="text-end small text-muted">
                                <?php echo e(date('d M Y', strtotime($item['created_at']))); ?>
                                <?php if ($item['author']): ?>
                                    <br><span class="text-muted">by <?php echo e($item['author']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo url('admin/announcements.php?edit=' . (int) $item['id']); ?>" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="post" action="<?php echo url('admin/announcements.php'); ?>" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $item['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete this announcement?" title="Delete">
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
