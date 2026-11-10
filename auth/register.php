<?php
/**
 * Student self-registration.
 */
require_once __DIR__ . '/../includes/auth.php';

require_guest();

$errors = [];
$old    = ['reg_number' => '', 'full_name' => '', 'email' => '', 'department' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['reg_number'] = input('reg_number');
    $old['full_name']  = input('full_name');
    $old['email']      = input('email');
    $old['department'] = input('department');
    $password          = isset($_POST['password']) ? (string) $_POST['password'] : '';
    $confirm           = isset($_POST['confirm_password']) ? (string) $_POST['confirm_password'] : '';

    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $errors[] = 'Your session expired. Please try again.';
    }
    $old['reg_number'] = normalize_reg_number($old['reg_number']);
    foreach (reg_number_errors($old['reg_number']) as $regError) {
        $errors[] = $regError;
    }
    if ($old['full_name'] === '') {
        $errors[] = 'Full name is required.';
    }
    if (!is_valid_email($old['email'])) {
        $errors[] = 'A valid email address is required.';
    }
    foreach (password_policy_errors($password) as $policyError) {
        $errors[] = $policyError;
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    if (!$errors) {
        $pdo = db();

        $check = $pdo->prepare('SELECT id FROM students WHERE email = ? OR reg_number = ? LIMIT 1');
        $check->execute([$old['email'], $old['reg_number']]);
        if ($check->fetch()) {
            $errors[] = 'An account with that email or registration number already exists.';
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO students (reg_number, full_name, email, password, department)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $old['reg_number'],
                $old['full_name'],
                $old['email'],
                password_hash($password, PASSWORD_DEFAULT),
                $old['department'] !== '' ? $old['department'] : null,
            ]);
            $studentId = (int) $pdo->lastInsertId();
            // Optional profile picture at registration.
            if (isset($_FILES['profile_picture']) && isset($_FILES['profile_picture']['error']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
                require_once __DIR__ . '/../includes/profile_picture.php';
                $res = handle_profile_picture_upload('student', $_FILES['profile_picture'], null);
                if ($res['ok']) {
                    $pdo->prepare('UPDATE students SET picture = ? WHERE id = ?')->execute(array($res['filename'], $studentId));
                }
            }

            set_flash('success', 'Registration successful. You can now log in with your registration number and password.');
            redirect('auth/login.php');
        }
    }
}

$page_title = 'Student Registration';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrapper">
    <div class="card auth-card">
        <div class="card-body p-4 p-md-5">
            <h1 class="h4 text-center mb-1">Create your account</h1>
            <p class="text-center text-muted mb-4">Student registration</p>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo url('auth/register.php'); ?>" enctype="multipart/form-data" novalidate>
                <?php echo csrf_field(); ?>

                <div class="mb-3">
                    <label for="reg_number" class="form-label">Student / Registration number</label>
                    <input type="text" class="form-control" id="reg_number" name="reg_number"
                           value="<?php echo e($old['reg_number']); ?>" required>
                    <div class="form-text"><?php echo e(reg_number_hint()); ?> This is your unique identity for login.</div>
                </div>

                <div class="mb-3">
                    <label for="full_name" class="form-label">Full name</label>
                    <input type="text" class="form-control" id="full_name" name="full_name"
                           value="<?php echo e($old['full_name']); ?>" required>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">Email address</label>
                    <input type="email" class="form-control" id="email" name="email"
                           value="<?php echo e($old['email']); ?>" required>
                </div>

                <div class="mb-3">
                    <label for="department" class="form-label">Department <span class="text-muted">(optional)</span></label>
                    <input type="text" class="form-control" id="department" name="department"
                           value="<?php echo e($old['department']); ?>">
                </div>

                <div class="mb-3">
                    <label for="profile_picture" class="form-label">Profile picture <span class="text-muted">(optional, JPG/PNG/GIF/WebP, max 2MB)</span></label>
                    <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/gif,image/webp">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password"
                           minlength="6" required>
                    <div class="form-text"><?php echo e(password_policy_hint()); ?></div>
                    <ul class="small text-muted mt-2 mb-0" id="pwRules">
                        <li data-rule="length">At least 6 characters</li>
                        <li data-rule="upper">One capital letter (A-Z)</li>
                        <li data-rule="lower">One small letter (a-z)</li>
                        <li data-rule="number">One number (0-9)</li>
                        <li data-rule="space">No spaces</li>
                    </ul>
                </div>

                <div class="mb-4">
                    <label for="confirm_password" class="form-label">Confirm password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                           minlength="6" required>
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-person-plus me-1"></i>Register
                </button>
            </form>

            <script>
            (function () {
                var pw = document.getElementById('password');
                var rules = document.getElementById('pwRules');
                if (!pw || !rules) { return; }
                pw.addEventListener('input', function () {
                    var v = pw.value;
                    var checks = {
                        length: v.length >= 6,
                        upper: /[A-Z]/.test(v),
                        lower: /[a-z]/.test(v),
                        number: /[0-9]/.test(v),
                        space: !/\s/.test(v)
                    };
                    rules.querySelectorAll('li').forEach(function (li) {
                        var ok = checks[li.getAttribute('data-rule')];
                        li.classList.toggle('text-success', ok);
                        li.classList.toggle('text-muted', !ok);
                        li.textContent = (ok ? '✓ ' : '• ') + li.textContent.replace(/^[✓•]\s*/, '');
                    });
                });
            })();
            </script>

            <p class="text-center mt-4 mb-0">
                Already registered? <a href="<?php echo url('auth/login.php'); ?>">Log in</a>
            </p>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
