<?php
/**
 * Role-aware top navigation bar.
 * Shows the A + A² admin logo on admin pages and the graduation-cap
 * student logo on student pages.
 */
require_once __DIR__ . '/functions.php';

$theme = page_theme();
$user = current_user();
// Resolve a profile picture URL when available (guard: column may not exist on old DBs).
$picUrl = null;
$picInit = null;
if ($user !== null) {
    $picInit = '?';
    if (function_exists('profile_pic_initials') && isset($user['name'])) {
        $picInit = profile_pic_initials($user['name']);
    }
    if (isset($user['picture']) && $user['picture'] !== '' && function_exists('profile_pic_url')) {
        $picUrl = profile_pic_url($user['role'], $user['picture']);
    }
}
?>
<nav class="navbar navbar-expand-lg navbar-dark app-navbar shadow-sm nav-theme-<?php echo e($theme); ?>">
    <div class="container">
        <a class="navbar-brand fw-semibold d-flex align-items-center gap-2" href="<?php echo url('index.php'); ?>">
            <?php if ($theme === 'admin' || is_admin()): ?>
                <?php echo admin_logo('sm'); ?>
                <span>Online Exam System</span>
            <?php elseif ($theme === 'student' || is_student()): ?>
                <?php echo student_logo('sm'); ?>
                <span>Online Exam System</span>
            <?php else: ?>
                <i class="bi bi-mortarboard-fill me-1"></i>Online Exam System
            <?php endif; ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if (is_admin()): ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo url('admin/dashboard.php'); ?>"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo url('admin/examinations.php'); ?>"><i class="bi bi-journal-text me-1"></i>Examinations</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo url('admin/ai_exam.php'); ?>"><i class="bi bi-robot me-1"></i>AI Generator</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo url('admin/students.php'); ?>"><i class="bi bi-people me-1"></i>Students</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo url('admin/results.php'); ?>"><i class="bi bi-clipboard-data me-1"></i>Results</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo url('admin/reports.php'); ?>"><i class="bi bi-file-earmark-bar-graph me-1"></i>Reports</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo url('admin/announcements.php'); ?>"><i class="bi bi-megaphone me-1"></i>Announcements</a></li>
                <?php elseif (is_student()): ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo url('student/dashboard.php'); ?>"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo url('student/examinations.php'); ?>"><i class="bi bi-journal-text me-1"></i>Examinations</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo url('student/history.php'); ?>"><i class="bi bi-clock-history me-1"></i>History</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo url('student/profile.php'); ?>"><i class="bi bi-person-gear me-1"></i>Profile</a></li>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav ms-auto">
                <?php if (is_logged_in()): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" id="userMenu" role="button"
                           data-bs-toggle="dropdown" aria-expanded="false">
                            <?php if ($picUrl): ?>
                                <img src="<?php echo e($picUrl); ?>" alt="" class="nav-avatar" width="28" height="28">
                            <?php else: ?>
                                <span class="nav-avatar nav-avatar-fallback"><?php echo e($picInit); ?></span>
                            <?php endif; ?>
                            <i class="bi bi-person-circle me-1"></i><?php echo e(current_user()['name']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userMenu">
                            <li><span class="dropdown-item-text small text-muted">
                                <?php echo is_admin() ? 'Administrator' : 'Student'; ?>
                            </span></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo url(is_admin() ? 'admin/profile.php' : 'student/profile.php'); ?>">
                                <i class="bi bi-person-gear me-1"></i>My Profile
                            </a></li>
                            <li><a class="dropdown-item" href="<?php echo url('auth/logout.php'); ?>">
                                <i class="bi bi-box-arrow-right me-1"></i>Log out
                            </a></li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo url('auth/login.php'); ?>">Login</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo url('auth/register.php'); ?>">Register</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
