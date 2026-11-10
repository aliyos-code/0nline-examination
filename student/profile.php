<?php
/**
 * Student: view and update their own profile, and change their password.
 */
require_once __DIR__ . '/../includes/auth.php';
require_student();
require_once __DIR__ . '/../includes/profile_picture.php';

$pdo       = db();
$studentId = (int) current_user()['id'];

$errors    = [];
$pwErrors  = [];
$picErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        set_flash('danger', 'Your session expired. Please try again.');
        redirect('student/profile.php');
    }

    $action = input('action');

    if ($action === 'upload_picture') {
        $stmt = $pdo->prepare('SELECT picture FROM students WHERE id = ?');
        $stmt->execute([$studentId]);
        $oldPic = $stmt->fetchColumn();
        $res = handle_profile_picture_upload('student', isset($_FILES['profile_picture']) ? $_FILES['profile_picture'] : null, $oldPic ? (string) $oldPic : null);
        if (!$res['ok']) {
            $picErrors[] = $res['error'] ? $res['error'] : 'Upload failed.';
        } else {
            $pdo->prepare('UPDATE students SET picture = ? WHERE id = ?')->execute([$res['filename'], $studentId]);
            $_SESSION['user']['picture'] = $res['filename'];
            set_flash('success', 'Profile photo updated.');
            redirect('student/profile.php');
        }
    } elseif ($action === 'update_profile') {
        $fullName   = input('full_name');
        $department = input('department');

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE students SET full_name = ?, department = ? WHERE id = ?');
            $stmt->execute([$fullName, $department !== '' ? $department : null, $studentId]);

            // Keep the session (and navbar) in sync.
            $_SESSION['user']['name'] = $fullName;

            set_flash('success', 'Your profile has been updated.');
            redirect('student/profile.php');
        }
    } elseif ($action === 'change_password') {
        $current = isset($_POST['current_password']) ? (string) $_POST['current_password'] : '';
        $new     = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
        $confirm = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

        $stmt = $pdo->prepare('SELECT password FROM students WHERE id = ?');
        $stmt->execute([$studentId]);
        $hash = (string) $stmt->fetchColumn();

        if ($current === '' || !password_verify($current, $hash)) {
            $pwErrors[] = 'Your current password is incorrect.';
        }
        foreach (password_policy_errors($new) as $policyError) {
            $pwErrors[] = $policyError;
        }
        if ($new !== $confirm) {
            $pwErrors[] = 'The two new passwords do not match.';
        }
        if ($new !== '' && $current !== '' && $new === $current) {
            $pwErrors[] = 'The new password must be different from the current one.';
        }

        if (!$pwErrors) {
            $stmt = $pdo->prepare('UPDATE students SET password = ? WHERE id = ?');
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $studentId]);
            set_flash('success', 'Your password has been changed.');
            redirect('student/profile.php');
        }
    }
}

$stmt = $pdo->prepare('SELECT * FROM students WHERE id = ?');
$stmt->execute([$studentId]);
$student = $stmt->fetch();

$page_title = 'My Profile';
require __DIR__ . '/../includes/header.php';
?>
<h1 class="h3 mb-4">My Profile</h1>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Profile details</div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo url('student/profile.php'); ?>" novalidate>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="mb-3">
                        <label class="form-label">Registration number</label>
                        <input type="text" class="form-control" value="<?php echo e($student['reg_number']); ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email address</label>
                        <input type="text" class="form-control" value="<?php echo e($student['email']); ?>" disabled>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full name</label>
                        <input type="text" class="form-control" name="full_name"
                               value="<?php echo e($student['full_name']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Department <span class="text-muted">(optional)</span></label>
                        <input type="text" class="form-control" name="department"
                               value="<?php echo e($student['department']); ?>">
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Save changes
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Profile photo</div>
            <div class="card-body">
                <?php if ($picErrors): ?>
                    <div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($picErrors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <?php if (isset($student['picture']) && $student['picture'] !== ''): ?>
                    <div class="text-center mb-3"><img src="<?php echo e(profile_pic_url('student', $student['picture'])); ?>" alt="Profile photo" class="profile-photo"></div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" action="<?php echo url('student/profile.php'); ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="upload_picture">
                    <div class="mb-3">
                        <label class="form-label">Choose photo</label>
                        <input type="file" class="form-control" name="profile_picture" accept="image/jpeg,image/png,image/gif,image/webp" required>
                        <div class="form-text">JPG/PNG/GIF/WebP, max 2MB.</div>
                    </div>
                    <button type="submit" class="btn btn-success"><i class="bi bi-upload me-1"></i>Upload photo</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Change password</div>
            <div class="card-body">
                <?php if ($pwErrors): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($pwErrors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="<?php echo url('student/profile.php'); ?>" novalidate>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label class="form-label">Current password</label>
                        <input type="password" class="form-control" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New password</label>
                        <input type="password" class="form-control" name="new_password" minlength="6" required>
                        <div class="form-text"><?php echo e(password_policy_hint()); ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm new password</label>
                        <input type="password" class="form-control" name="confirm_password" minlength="6" required>
                    </div>

                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-key me-1"></i>Change password
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
