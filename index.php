<?php
/**
 * Landing page - university style front page.
 *
 * Guests see a welcome hero, quick access links, the latest announcements and
 * an overview of the platform. Authenticated users are sent to their
 * dashboard straight away.
 */
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'admin/dashboard.php' : 'student/dashboard.php');
}

$page_title = 'Home';
require __DIR__ . '/includes/header.php';

// Latest announcements for the public notice board.
$announcements = [];
try {
    $announcements = db()->query(
        "SELECT title, body, created_at FROM announcements
         WHERE audience IN ('students', 'all')
         ORDER BY created_at DESC, id DESC
         LIMIT 3"
    )->fetchAll();
} catch (PDOException $e) {
    $announcements = [];
}
?>

<!-- Quick access strip -->
<div class="bg-white border-bottom">
    <div class="container py-2 d-flex flex-wrap justify-content-between align-items-center small">
        <div class="text-muted">
            <i class="bi bi-envelope me-1"></i>info@onlineexamsystem.edu
            <span class="d-none d-md-inline mx-3">|</span>
            <i class="bi bi-clock me-1"></i>Support: Mon&ndash;Fri, 8:00am &ndash; 5:00pm
        </div>
        <div class="d-flex gap-3">
            <a href="<?php echo url('auth/login.php'); ?>" class="text-decoration-none">Student Login</a>
            <a href="<?php echo url('auth/register.php'); ?>" class="text-decoration-none">New Student</a>
            <a href="<?php echo url('auth/login.php?role=admin'); ?>" class="text-decoration-none">Staff Login</a>
        </div>
    </div>
</div>

<!-- Hero -->
<section class="container my-4">
    <div class="hero p-4 p-lg-5">
        <div class="hero-content row align-items-center g-4">
            <div class="col-lg-7">
                <span class="badge text-bg-warning text-uppercase fw-semibold mb-3">
                    <i class="bi bi-stars me-1"></i>Examination Portal
                </span>
                <h1 class="display-5 fw-bold mb-3">Online Examination &amp; Result Management System</h1>
                <p class="lead mb-4" style="max-width: 46rem;">
                    A single, secure platform for setting examinations, writing questions,
                    supervising candidates and publishing results &mdash; built for schools
                    and departments that need reliability and a complete audit trail.
                </p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="<?php echo url('auth/register.php'); ?>" class="btn btn-warning btn-lg fw-semibold">
                        <i class="bi bi-person-plus me-1"></i>Register as Student
                    </a>
                    <a href="<?php echo url('auth/login.php'); ?>" class="btn btn-outline-light btn-lg">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Login to Portal
                    </a>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="row g-3">
                    <div class="col-6">
                        <div class="text-center p-3 rounded" style="background: rgba(255,255,255,.12);">
                            <div class="h2 fw-bold mb-0">24/7</div>
                            <div class="small">Exam access</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-center p-3 rounded" style="background: rgba(255,255,255,.12);">
                            <div class="h2 fw-bold mb-0">Auto</div>
                            <div class="small">Marking &amp; results</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-center p-3 rounded" style="background: rgba(255,255,255,.12);">
                            <div class="h2 fw-bold mb-0">AI</div>
                            <div class="small">Paper generator</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-center p-3 rounded" style="background: rgba(255,255,255,.12);">
                            <div class="h2 fw-bold mb-0">Full</div>
                            <div class="small">Audit trail</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Quick links -->
<section class="container mb-5">
    <div class="row g-3">
        <?php
        $quickLinks = [
            ['auth/register.php', 'bi-person-plus', 'Register', 'Create your student account in a minute.'],
            ['auth/login.php', 'bi-box-arrow-in-right', 'Student Login', 'Take or continue an examination.'],
            ['auth/login.php?role=admin', 'bi-clipboard-data', 'Staff Portal', 'Set papers, mark and publish results.'],
            ['student/history.php', 'bi-clock-history', 'My Results', 'Review your scores and print result sheets.'],
        ];
        foreach ($quickLinks as $link):
        ?>
            <div class="col-6 col-lg-3">
                <a href="<?php echo url($link[0]); ?>" class="text-decoration-none text-body">
                    <div class="card feature-card p-3 h-100">
                        <span class="feature-icon mb-2"><i class="bi <?php echo e($link[1]); ?>"></i></span>
                        <div class="fw-semibold"><?php echo e($link[2]); ?></div>
                        <div class="small text-muted"><?php echo e($link[3]); ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Notices + features -->
<section class="container mb-5">
    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-megaphone me-1 text-warning"></i>Notice Board
                </div>
                <?php if (!$announcements): ?>
                    <div class="card-body text-muted small">
                        There are no notices at the moment. Please check back later.
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($announcements as $notice): ?>
                            <div class="list-group-item py-3">
                                <div class="fw-semibold"><?php echo e($notice['title']); ?></div>
                                <div class="small text-muted"><?php echo e(mb_strimwidth($notice['body'], 0, 120, '...')); ?></div>
                                <div class="small text-muted fst-italic mt-1">
                                    <?php echo e(date('d M Y', strtotime($notice['created_at']))); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-7">
            <h2 class="h4 mb-3">What the platform does</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card feature-card h-100 p-3">
                        <span class="feature-icon mb-2"><i class="bi bi-journal-check"></i></span>
                        <h3 class="h6">Examination setup</h3>
                        <p class="small text-muted mb-0">
                            Create a paper with its course code, duration, pass mark, attempt limit
                            and an opening/closing window.
                        </p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card feature-card h-100 p-3">
                        <span class="feature-icon mb-2"><i class="bi bi-shield-lock"></i></span>
                        <h3 class="h6">Secure sitting</h3>
                        <p class="small text-muted mb-0">
                            Server-side timers, focus-loss and tab-switch monitoring, hashed
                            passwords and CSRF protection on every form.
                        </p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card feature-card h-100 p-3">
                        <span class="feature-icon mb-2"><i class="bi bi-magic"></i></span>
                        <h3 class="h6">AI paper generator</h3>
                        <p class="small text-muted mb-0">
                            Give the assistant a topic and it drafts a full set of objective
                            questions you can edit before publishing.
                        </p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card feature-card h-100 p-3">
                        <span class="feature-icon mb-2"><i class="bi bi-graph-up"></i></span>
                        <h3 class="h6">Analytics &amp; print</h3>
                        <p class="small text-muted mb-0">
                            Pass-rate analytics per examination, CSV exports and printable
                            result sheets signed by the invigilator.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Process -->
<section class="container mb-5">
    <div class="card shadow-sm border-0">
        <div class="card-body p-4">
            <h2 class="h4 mb-4 text-center">How it works</h2>
            <div class="row g-4 text-center">
                <?php
                $steps = [
                    ['bi-person-check', '1. Register', 'Students sign up with their registration number and email.'],
                    ['bi-journal-plus', '2. Set the paper', 'Staff add questions, either by hand, by CSV or with the AI generator.'],
                    ['bi-pencil-square', '3. Sit the exam', 'Candidates answer inside a timed, proctored attempt.'],
                    ['bi-file-earmark-check', '4. Publish results', 'Submissions are marked instantly; results and analytics are published.'],
                ];
                foreach ($steps as $step):
                ?>
                    <div class="col-md-3">
                        <i class="bi <?php echo e($step[0]); ?> fs-1 text-primary"></i>
                        <h3 class="h6 mt-2"><?php echo e($step[1]); ?></h3>
                        <p class="small text-muted mb-0"><?php echo e($step[2]); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<!-- Call to action -->
<section class="container mb-5">
    <div class="rounded p-4 p-lg-5 text-center text-white" style="background: linear-gradient(135deg, #b45309, #f59e0b);">
        <h2 class="h3 fw-bold">Ready to begin?</h2>
        <p class="mb-4 mx-auto" style="max-width: 40rem;">
            Create your account today and take your first examination with instant results.
        </p>
        <a href="<?php echo url('auth/register.php'); ?>" class="btn btn-light btn-lg fw-semibold">
            <i class="bi bi-person-plus me-1"></i>Register Now
        </a>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
