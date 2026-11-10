<?php
/**
 * Administrator: system settings (registration-number format, etc.).
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pdo = db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        set_flash('danger', 'Your session expired. Please try again.');
        redirect('admin/settings.php');
    }
    $format = input('reg_number_format', 'flexible');
    if (!isset(reg_number_formats()[$format])) {
        $errors[] = 'Please choose a valid registration-number format.';
    }
    if (!$errors) {
        set_setting('reg_number_format', $format);
        audit_log('settings.update', 'setting', null, 'reg_number_format=' . $format);
        set_flash('success', 'Settings saved.');
        redirect('admin/settings.php');
    }
}

$page_title = 'System Settings';
$active = 'settings';
require __DIR__ . '/../includes/header.php';
?>
<div class="row">
    <div class="col-lg-3 mb-4">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    </div>
    <div class="col-lg-9">
        <h1 class="h3 mb-4">System Settings</h1>
        <?php if ($errors): ?>
            <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Registration-number format</div>
            <div class="card-body">
                <p class="text-muted">
                    The registration number is each student's unique identity for login. Choose the
                    institution's format. A date format such as <code>28/11/2006</code> is only a
                    format — the system does not assume it is the student's date of birth.
                </p>
                <form method="post" action="<?php echo url('admin/settings.php'); ?>">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label">Accepted format</label>
                        <select class="form-select" name="reg_number_format">
                            <?php foreach (reg_number_formats() as $val => $label): ?>
                                <option value="<?php echo e($val); ?>" <?php echo reg_number_format() === $val ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Current examples: alphanumeric <code>UG24/ICTC/1099</code>, date <code>28/11/2006</code>. Existing registration numbers are never changed.</div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save settings</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
