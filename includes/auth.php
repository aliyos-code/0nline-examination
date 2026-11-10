<?php
/**
 * Authentication / authorization guards.
 *
 * Every protected page includes this file and calls one of the guard
 * functions below. Authorization is always enforced on the server, never by
 * merely hiding navigation links.
 */

require_once __DIR__ . '/functions.php';

/**
 * Bind the session to the current user-agent to make stolen sessions harder
 * to reuse (basic session-hijacking mitigation).
 */
function bind_session_to_agent()
{
    $agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    $fingerprint = hash('sha256', $agent);

    if (!isset($_SESSION['fingerprint'])) {
        $_SESSION['fingerprint'] = $fingerprint;
    } elseif (!hash_equals($_SESSION['fingerprint'], $fingerprint)) {
        // User agent changed mid-session: destroy and force re-login.
        $_SESSION = [];
        session_destroy();
        session_start();
        set_flash('warning', 'Your session expired for security reasons. Please log in again.');
        redirect('auth/login.php');
    }
}

/**
 * Enforce idle and absolute session lifetimes.
 *
 * The idle timer is suspended while an examination attempt is in progress so a
 * candidate is never logged out mid-paper; the exam's own server-side deadline
 * still governs that scenario.
 */
function enforce_session_timeout()
{
    if (!is_logged_in()) {
        return;
    }

    $now = time();

    if (isset($_SESSION['login_time']) && ($now - (int) $_SESSION['login_time']) > SESSION_ABSOLUTE_TIMEOUT) {
        expire_session('Your session has reached its maximum duration. Please log in again.');
    }

    $inExam = isset($_SESSION['attempt_id']) && (int) $_SESSION['attempt_id'] > 0;
    if (!$inExam && isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        expire_session('You were signed out after a period of inactivity.');
    }

    $_SESSION['last_activity'] = $now;
}

/**
 * Destroy the current session and send the user back to the login page.
 *
 * @param string $message
 */
function expire_session($message)
{
    $_SESSION = [];
    session_destroy();
    session_start();
    set_flash('warning', $message);
    redirect('auth/login.php');
}

/**
 * Require any authenticated user. Optionally restrict to a role.
 *
 * @param string|null $role 'admin' | 'student' | null
 */
function require_login($role = null)
{
    bind_session_to_agent();

    if (!is_logged_in()) {
        set_flash('warning', 'Please log in to continue.');
        redirect('auth/login.php');
    }

    enforce_session_timeout();

    if ($role !== null && (!isset($_SESSION['user']['role']) || $_SESSION['user']['role'] !== $role)) {
        set_flash('danger', 'You are not authorized to access that page.');
        // Send the user back to their own dashboard.
        redirect(is_admin() ? 'admin/dashboard.php' : 'student/dashboard.php');
    }
}

/**
 * Require an administrator.
 */
function require_admin()
{
    require_login('admin');
}

/**
 * Require a student.
 */
function require_student()
{
    require_login('student');
}

/**
 * Prevent an already logged-in user from viewing login/register pages.
 */
function require_guest()
{
    if (is_logged_in()) {
        redirect(is_admin() ? 'admin/dashboard.php' : 'student/dashboard.php');
    }
}
