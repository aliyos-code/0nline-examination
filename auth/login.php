<?php
/**
 * Login for administrator (authorized full name + password) and
 * students (registration number, full name or email + password).
 * The registration number is the student's unique identity.
 */
require_once __DIR__ . '/../includes/auth.php';

require_guest();

$errors  = [];
$role    = (input('role') === 'admin') ? 'admin' : 'student';
$login   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role  = input('role', 'student');
    // Primary: full_name. Fallback: email (older accounts).
    $fullName = input('full_name');
    if ($fullName === '') {
        $fullName = input('login_name');
    }
    $email = input('email');
    $login = $fullName !== '' ? $fullName : $email;
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        $errors[] = 'Your session expired. Please try again.';
    }
    if ($role !== 'admin' && $role !== 'student') {
        $errors[] = 'Please select a valid account type.';
    }
    if ($login === '') {
        $errors[] = 'Please enter your registration number or full name.';
    }
    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    if (!$errors) {
        $pdo = db();

        // --- Brute-force throttling ---------------------------------------
        $windowMinutes = 15;
        $maxFailures   = 5;
        $ip            = client_ip();
        $loginKey      = normalize_full_name($login);

        $throttle = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE success = 0
               AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
               AND (email = ? OR ip_address = ?)'
        );
        $throttle->execute([$windowMinutes, $loginKey, $ip]);
        $recentFailures = (int) $throttle->fetchColumn();

        if ($recentFailures >= $maxFailures) {
            $errors[] = 'Too many failed login attempts. Please wait ' . $windowMinutes . ' minutes and try again.';
        } else {
            $account = null;
            if ($role === 'admin') {
                // Authorized admin: full name (case-insensitive) OR email fallback.
                $stmt  = $pdo->prepare('SELECT * FROM admins WHERE LOWER(TRIM(full_name)) = ? OR LOWER(TRIM(email)) = ? LIMIT 1');
                $stmt->execute([$loginKey, $loginKey]);
                $account = $stmt->fetch();
            } else {
                // Students: registration number (unique identity), full name or email.
                $stmt  = $pdo->prepare('SELECT * FROM students WHERE LOWER(TRIM(reg_number)) = ? OR LOWER(TRIM(full_name)) = ? OR LOWER(TRIM(email)) = ? LIMIT 1');
                $stmt->execute([$loginKey, $loginKey, $loginKey]);
                $account = $stmt->fetch();
            }

            $success = ($account && password_verify($password, $account['password']));

            // Deactivated student accounts cannot log in.
            if ($success && $role === 'student' && isset($account['status']) && $account['status'] !== 'active') {
                $success = false;
                $account = null;
                $errors[] = 'Your account has been deactivated. Please contact the administrator.';
            }

            // Enforce: only the authorized admin named below may enter admin area.
            if ($success && $role === 'admin' && normalize_full_name($account['full_name']) !== normalize_full_name('Aliyu Abdussalam Abdullah')) {
                // Still allow the existing single admin row that predates this rule
                // only if it is the sole admin and its name differs by setup? NO —
                // per requirement only Aliyu Abdussalam Abdullah may access admin.
                // If the stored admin name differs, treat as unauthorized.
                // (The migration script renames the admin row to Aliyu.)
                $success = false;
                $account = null;
            }

            // Record the attempt for throttling and the security trail.
            $log = $pdo->prepare(
                'INSERT INTO login_attempts (email, role, ip_address, success) VALUES (?, ?, ?, ?)'
            );
            $log->execute([$loginKey, $role, $ip, $success ? 1 : 0]);

            if ($success) {
                session_regenerate_id(true);
                $picture = null;
                if (isset($account['picture'])) {
                    $picture = $account['picture'];
                }
                $_SESSION['user'] = [
                    'id'      => (int) $account['id'],
                    'name'    => $account['full_name'],
                    'email'   => isset($account['email']) ? $account['email'] : '',
                    'role'    => $role,
                    'picture' => $picture,
                ];
                $_SESSION['fingerprint']   = hash('sha256', isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
                $_SESSION['login_time']    = time();
                $_SESSION['last_activity'] = time();

                set_flash('success', 'Welcome back, ' . $account['full_name'] . '.');
                redirect($role === 'admin' ? 'admin/dashboard.php' : 'student/dashboard.php');
            }

            usleep(300000);
            if (!$errors) {
                $errors[] = 'Invalid login details. Please check your registration number / full name and password.';
            }
        }
    }
}

$page_title = 'Login';
require __DIR__ . '/../includes/header.php';
?>
<div class="auth-wrapper">
    <div class="card auth-card">
        <div class="card-body p-4 p-md-5">
            <div class="auth-logo">
                <?php if ($role === 'admin'): ?>
                    <?php echo admin_logo('lg'); ?>
                <?php else: ?>
                    <?php echo student_logo('lg'); ?>
                <?php endif; ?>
            </div>
            <h1 class="h4 text-center mb-1">Sign in</h1>
            <p class="text-center text-muted mb-4">Online Examination System</p>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo url('auth/login.php'); ?>" novalidate>
                <?php echo csrf_field(); ?>

                <div class="mb-3">
                    <label class="form-label">Account type</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="role" id="roleStudent" value="student"
                               <?php echo $role === 'student' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-success" for="roleStudent"><i class="bi bi-mortarboard me-1"></i>Student</label>
                        <input type="radio" class="btn-check" name="role" id="roleAdmin" value="admin"
                               <?php echo $role === 'admin' ? 'checked' : ''; ?>>
                        <label class="btn btn-outline-primary" for="roleAdmin"><i class="bi bi-shield-lock me-1"></i>Administrator</label>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="full_name" class="form-label">Registration number / full name</label>
                    <input type="text" class="form-control" id="full_name" name="full_name"
                           value="<?php echo e($login); ?>" autocomplete="username" required>
                    <div class="form-text">Students: use your registration number (unique identity), full name or email. Admin: use your full name.</div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Login
                </button>
            </form>

            <p class="text-center mt-4 mb-0">
                Don't have a student account?
                <a href="<?php echo url('auth/register.php'); ?>">Register as a student</a>
            </p>

            <p class="small text-muted text-center mt-3 mb-0">
                Passwords must follow the policy: <?php echo e(password_policy_hint()); ?>
            </p>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
