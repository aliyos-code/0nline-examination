<?php
/**
 * Shared helper functions: sessions, output escaping, CSRF, flash messages,
 * validation helpers and examination marking.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/env.php';

// ---------------------------------------------------------------------------
// Secure session bootstrap
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        // 'secure' => true,  // Enable when serving over HTTPS.
    ]);
    session_start();
}

// ---------------------------------------------------------------------------
// Session lifetimes (seconds)
// ---------------------------------------------------------------------------
define('SESSION_IDLE_TIMEOUT', 3600);      // sign out after 60 min without activity
define('SESSION_ABSOLUTE_TIMEOUT', 28800); // hard cap of 8 hours per login

/**
 * Escape a value for safe HTML output (XSS protection).
 *
 * @param  mixed $value
 * @return string
 */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Determine which visual theme applies to the current page.
 * Admin pages get the blue/white/gold theme, student pages the
 * green/white theme. Call before including header.php by setting
 * $page_theme, or it is derived from the request path.
 *
 * @return string 'admin'|'student'|'public'
 */
function page_theme()
{
    global $page_theme;
    if (isset($page_theme) && in_array($page_theme, array('admin', 'student', 'public'), true)) {
        return $page_theme;
    }
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
    $script = str_replace('\\', '/', $script);
    if (strpos($script, '/admin/') !== false || substr($script, -15) === '/admin/index.php') {
        return 'admin';
    }
    if (strpos($script, '/student/') !== false) {
        return 'student';
    }
    return 'public';
}

/**
 * Normalize a full name for case-insensitive login matching.
 * Trims extra spaces and lowercases. Uses multibyte when available.
 *
 * @param string $name
 * @return string
 */
function normalize_full_name($name)
{
    $name = trim((string) $name);
    $name = preg_replace('/\s+/', ' ', $name);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($name, 'UTF-8');
    }
    return strtolower($name);
}

/**
 * Render the admin logo.
 *
 * Uses the administrator photo at assets/images/admin-logo.jpg
 * (circular crop) when that file is present; falls back to the
 * A + A² inline SVG badge if it is not.
 *
 * @param string $size 'sm'|'md'|'lg'
 */
function admin_logo($size = 'md')
{
    $dim = array('sm' => '34', 'md' => '46', 'lg' => '64');
    $d = isset($dim[$size]) ? $dim[$size] : $dim['md'];
    $logoFile = null;
    foreach (array('admin-logo.jpg', 'admin-logo.jpeg', 'admin-logo.png', 'admin-logo.webp') as $f) {
        if (is_file(__DIR__ . '/../assets/images/' . $f)) {
            $logoFile = $f;
            break;
        }
    }
    if ($logoFile !== null) {
        return '<span class="logo-badge logo-admin" aria-label="Administrator" title="Administrator">'
            . '<img src="' . e(url('assets/images/' . $logoFile)) . '" width="' . $d . '" height="' . $d . '" alt="Administrator" class="admin-logo-img">'
            . '</span>';
    }
    // No static branding file: use the administrator's uploaded profile
    // picture (My Profile), so the logo updates after a browser upload.
    $pic = null;
    if (function_exists('current_user')) {
        $u = current_user();
        if ($u !== null && isset($u['picture']) && $u['picture'] !== '') {
            $pic = $u['picture'];
        }
    }
    if ($pic === null) {
        try {
            $row = db()->query("SELECT picture FROM admins WHERE picture IS NOT NULL AND picture <> '' ORDER BY id ASC LIMIT 1")->fetch();
            if ($row && !empty($row['picture'])) {
                $pic = $row['picture'];
            }
        } catch (Exception $ex) {
        } catch (Error $ex) {
        }
    }
    if ($pic !== null && is_file(__DIR__ . '/../assets/uploads/admins/' . basename($pic))) {
        return '<span class="logo-badge logo-admin" aria-label="Administrator" title="Administrator">'
            . '<img src="' . e(url('assets/uploads/admins/' . rawurlencode(basename($pic)))) . '" width="' . $d . '" height="' . $d . '" alt="Administrator" class="admin-logo-img">'
            . '</span>';
    }
    $font = array('sm' => '15px', 'md' => '20px', 'lg' => '28px');
    $f = isset($font[$size]) ? $font[$size] : $font['md'];
    return '<span class="logo-badge logo-admin" aria-label="A plus A squared admin logo" title="A + A-squared">'
        . '<svg width="' . $d . '" height="' . $d . '" viewBox="0 0 64 64" role="img" aria-label="A plus A squared">'
        . '<circle cx="32" cy="32" r="30" fill="#0d3b66" stroke="#c9a227" stroke-width="3"/>'
        . '<text x="18" y="38" font-family="Georgia, serif" font-size="' . $f . '" font-weight="bold" fill="#ffffff">A</text>'
        . '<text x="38" y="30" font-family="Georgia, serif" font-size="' . $f . '" font-weight="bold" fill="#f4c542">A</text>'
        . '<text x="50" y="20" font-family="Georgia, serif" font-size="11px" fill="#f4c542">2</text>'
        . '<text x="27" y="36" font-family="Georgia, serif" font-size="' . $f . '" font-weight="bold" fill="#f4c542">+</text>'
        . '</svg></span>';
}

/**
 * Render the student graduation-cap logo (inline SVG).
 *
 * @param string $size 'sm'|'md'|'lg'
 */
function student_logo($size = 'md')
{
    $dim = array('sm' => '34', 'md' => '46', 'lg' => '64');
    $d = isset($dim[$size]) ? $dim[$size] : $dim['md'];
    return '<span class="logo-badge logo-student" aria-label="Student graduation cap logo" title="Student">'
        . '<svg width="' . $d . '" height="' . $d . '" viewBox="0 0 64 64" role="img" aria-label="Graduation cap">'
        . '<circle cx="32" cy="32" r="30" fill="#0b6e3f" stroke="#ffffff" stroke-width="3"/>'
        . '<path d="M32 17 L54 27 L32 37 L10 27 Z" fill="#ffffff"/>'
        . '<path d="M19 33 v11 c0 4 6 8 13 8 s13 -4 13 -8 v-11 l-13 5 Z" fill="#7dd3a8"/>'
        . '<line x1="54" y1="27" x2="54" y2="41" stroke="#f4c542" stroke-width="3"/>'
        . '<circle cx="54" cy="44" r="3" fill="#f4c542"/>'
        . '</svg></span>';
}

/**
 * Build an application URL from a path relative to the project root.
 *
 * @param  string $path
 * @return string
 */
function url($path = '')
{
    return APP_BASE . '/' . ltrim($path, '/');
}

/**
 * Redirect the browser and stop execution.
 *
 * @param string $path
 */
function redirect($path)
{
    header('Location: ' . (strpos($path, 'http') === 0 ? $path : url($path)));
    exit;
}

/**
 * Store a one-time flash message.
 *
 * @param string $type  Bootstrap alert type: success|danger|warning|info
 * @param string $message
 */
function set_flash($type, $message)
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Retrieve and clear all flash messages.
 *
 * @return array
 */
function get_flash()
{
    $messages = isset($_SESSION['flash']) ? $_SESSION['flash'] : [];
    unset($_SESSION['flash']);
    return $messages;
}

// ---------------------------------------------------------------------------
// CSRF protection
// ---------------------------------------------------------------------------

/**
 * Return (creating if necessary) the current session CSRF token.
 *
 * @return string
 */
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Hidden input field carrying the CSRF token.
 *
 * @return string
 */
function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Validate a submitted CSRF token using a timing-safe comparison.
 *
 * @param  string|null $token
 * @return bool
 */
function verify_csrf($token)
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ---------------------------------------------------------------------------
// Authentication state helpers
// ---------------------------------------------------------------------------

/**
 * The currently authenticated user, or null when logged out.
 *
 * @return array|null
 */
function current_user()
{
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

/**
 * @return bool
 */
function is_logged_in()
{
    return current_user() !== null;
}

/**
 * @return bool
 */
function is_admin()
{
    $user = current_user();
    return $user !== null && $user['role'] === 'admin';
}

/**
 * @return bool
 */
function is_student()
{
    $user = current_user();
    return $user !== null && $user['role'] === 'student';
}

// ---------------------------------------------------------------------------
// Small validation helpers
// ---------------------------------------------------------------------------

/**
 * Trim a POST/GET value or return a default.
 *
 * @param  string $key
 * @param  mixed  $default
 * @return string
 */
function input($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string) $_POST[$key]) : (isset($_GET[$key]) ? trim((string) $_GET[$key]) : $default);
}

/**
 * @param  mixed $value
 * @return bool
 */
function is_valid_email($value)
{
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

// ---------------------------------------------------------------------------
// Registration-number formats + system settings
// ---------------------------------------------------------------------------

/**
 * Institution-configured registration-number format.
 * 'flexible' (default) accepts alphanumeric numbers with / - _ (e.g.
 * UG24/ICTC/1099, STU001). 'date' accepts ONLY a strict dd/mm/yyyy date
 * such as 28/11/2006 when the institution uses that format - it is an
 * identifier format, NOT an assumption that it is the student's date of
 * birth. 'alphanumeric' accepts letters/digits with / - _ only.
 */
function reg_number_formats()
{
    return [
        'flexible'     => 'Flexible (letters, numbers, / - _)',
        'date'         => 'Date format dd/mm/yyyy (e.g. 28/11/2006)',
        'alphanumeric' => 'Alphanumeric only (letters, numbers, / - _)',
    ];
}

function reg_number_format()
{
    $v = get_setting('reg_number_format', 'flexible');
    return isset(reg_number_formats()[$v]) ? $v : 'flexible';
}

/** Normalise a registration number (trim + collapse spaces, keep case). */
function normalize_reg_number($value)
{
    $v = trim((string) $value);
    $v = preg_replace('/\s+/', '', $v);
    return $v;
}

/** Hint text for reg-number fields, following the configured format. */
function reg_number_hint()
{
    $f = reg_number_format();
    if ($f === 'date') {
        return 'Use the date format dd/mm/yyyy (e.g. 28/11/2006).';
    }
    if ($f === 'alphanumeric') {
        return 'Letters, numbers and / - _ only (e.g. UG24/ICTC/1099). No spaces.';
    }
    return 'Letters, numbers and / - _ (e.g. UG24/ICTC/1099 or 28/11/2006). No spaces.';
}

/**
 * Validate a registration number against the configured format.
 * @return array List of problems; empty means valid.
 */
function reg_number_errors($value, $format = null)
{
    if ($format === null) {
        $format = reg_number_format();
    }
    $v = normalize_reg_number($value);
    if ($v === '') {
        return ['Registration number is required.'];
    }
    if (strlen($v) < 3 || strlen($v) > 50) {
        return ['Registration number must be between 3 and 50 characters.'];
    }
    if (preg_match('/\s/', $v)) {
        return ['Registration number must not contain spaces.'];
    }
    if ($format === 'date') {
        if (!preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $v, $m)) {
            return ['Registration number must use the dd/mm/yyyy format (e.g. 28/11/2006).'];
        }
        if (!checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return ['Registration number is not a valid calendar date (dd/mm/yyyy).'];
        }
        return [];
    }
    if (!preg_match('/^[A-Za-z0-9\/\-_]+$/', $v)) {
        return ['Registration number may only contain letters, numbers and / - _.'];
    }
    return [];
}

function is_valid_reg_number($value, $format = null)
{
    return reg_number_errors($value, $format) === [];
}

/** Simple key/value system settings (table created by database/upgrade.php). */
function get_setting($key, $default = null)
{
    try {
        $stmt = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([(string) $key]);
        $v = $stmt->fetchColumn();
        return $v === false ? $default : $v;
    } catch (Exception $e) {
        return $default;
    } catch (Error $e) {
        return $default;
    }
}

function set_setting($key, $value)
{
    $stmt = db()->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([(string) $key, (string) $value]);
}

/** True if the exam is assigned to this student (or assigned to nobody). */
function student_can_see_exam($pdo, $examId, $studentId)
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM exam_assignments WHERE examination_id = ?');
        $stmt->execute([(int) $examId]);
        if ((int) $stmt->fetchColumn() === 0) {
            return true; // No assignments = available to all (existing behaviour).
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM exam_assignments WHERE examination_id = ? AND student_id = ?');
        $stmt->execute([(int) $examId, (int) $studentId]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return true;
    } catch (Error $e) {
        return true;
    }
}

// ---------------------------------------------------------------------------
// Examination logic
// ---------------------------------------------------------------------------

/**
 * Finalise an attempt: mark every answer, compute the score and persist the
 * result. Idempotent - a completed attempt is returned untouched.
 *
 * @param  int $attemptId
 * @return array|null The attempt row after finalisation.
 */
function finalize_attempt($attemptId)
{
    $pdo = db();

    $stmt = $pdo->prepare(
        'SELECT a.*, e.pass_mark
         FROM exam_attempts a
         JOIN examinations e ON e.id = a.examination_id
         WHERE a.id = ?'
    );
    $stmt->execute([$attemptId]);
    $attempt = $stmt->fetch();

    if (!$attempt) {
        return null;
    }
    if ($attempt['status'] === 'completed') {
        return $attempt;
    }

    // Pull every question assigned to this attempt together with its correct
    // answer and the student's saved selection (if any).
    $stmt = $pdo->prepare(
        'SELECT aq.question_id, q.correct_option, sa.selected_option
         FROM attempt_questions aq
         JOIN questions q ON q.id = aq.question_id
         LEFT JOIN student_answers sa
                ON sa.attempt_id = aq.attempt_id AND sa.question_id = aq.question_id
         WHERE aq.attempt_id = ?
         ORDER BY aq.display_order ASC'
    );
    $stmt->execute([$attemptId]);
    $rows = $stmt->fetchAll();

    $total   = count($rows);
    $correct = 0;

    $upsert = $pdo->prepare(
        'INSERT INTO student_answers (attempt_id, question_id, selected_option, is_correct)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE is_correct = VALUES(is_correct)'
    );

    foreach ($rows as $row) {
        $selected = $row['selected_option'];
        $isCorrect = ($selected !== null && $selected === $row['correct_option']) ? 1 : 0;
        $correct += $isCorrect;
        $upsert->execute([$attemptId, $row['question_id'], $selected, $isCorrect]);
    }

    $wrong      = $total - $correct;
    $percentage = $total > 0 ? round(($correct / $total) * 100, 2) : 0.0;
    $passFail   = ($percentage >= (float) $attempt['pass_mark']) ? 'pass' : 'fail';

    $update = $pdo->prepare(
        "UPDATE exam_attempts
         SET end_time = ?, score = ?, correct_answers = ?, wrong_answers = ?,
             percentage = ?, pass_fail = ?, status = 'completed', total_questions = ?
         WHERE id = ?"
    );
    $update->execute([
        date('Y-m-d H:i:s'),
        $correct,
        $correct,
        $wrong,
        $percentage,
        $passFail,
        $total,
        $attemptId,
    ]);

    $stmt = $pdo->prepare('SELECT * FROM exam_attempts WHERE id = ?');
    $stmt->execute([$attemptId]);
    return $stmt->fetch();
}

/**
 * Scheduling state of an examination: 'upcoming', 'open' or 'closed'.
 *
 * A NULL boundary means "no restriction" on that side. The window only governs
 * whether a *new* attempt may be started; an attempt already in progress is
 * bounded by its own server-side deadline.
 *
 * @param  array $exam  A row from the examinations table.
 * @return string
 */
function exam_window_state($exam)
{
    $now = time();
    if (!empty($exam['available_from']) && strtotime($exam['available_from']) > $now) {
        return 'upcoming';
    }
    if (!empty($exam['available_until']) && strtotime($exam['available_until']) < $now) {
        return 'closed';
    }
    return 'open';
}

/**
 * Single source of truth for examination visibility, used identically by
 * the Admin Dashboard, Student Dashboard, examinations lists and the
 * backend start-exam guard. DB status values: inactive = NOT YET OPEN,
 * active = opened by admin, closed = past closing time / manually closed.
 *
 * @return string 'NOT YET OPEN'|'OPEN'|'CLOSED'
 */
function exam_display_status($exam)
{
    $status = isset($exam['status']) ? $exam['status'] : 'inactive';
    if ($status === 'closed') {
        return 'CLOSED';
    }
    if (!empty($exam['available_until']) && strtotime($exam['available_until']) < time()) {
        return 'CLOSED';
    }
    if ($status !== 'active') {
        return 'NOT YET OPEN';
    }
    if (!empty($exam['available_from']) && strtotime($exam['available_from']) > time()) {
        return 'NOT YET OPEN';
    }
    return 'OPEN';
}

function exam_status_badge($exam)
{
    $s = exam_display_status($exam);
    if ($s === 'OPEN') {
        return '<span class="badge bg-success">OPEN</span>';
    }
    if ($s === 'CLOSED') {
        return '<span class="badge bg-secondary">CLOSED</span>';
    }
    return '<span class="badge bg-warning text-dark">NOT YET OPEN</span>';
}

/**
 * Lazily persist CLOSED status for examinations past their closing time.
 * Called at the top of every dashboard / list / start-exam page so the
 * database itself (not just the UI) reflects CLOSED.
 */
function auto_close_expired_exams($pdo = null)
{
    try {
        if ($pdo === null) {
            $pdo = db();
        }
        $stmt = $pdo->prepare(
            "UPDATE examinations SET status = 'closed'
             WHERE status IN ('active', 'inactive')
               AND available_until IS NOT NULL
               AND available_until < NOW()"
        );
        $stmt->execute();
        return $stmt->rowCount();
    } catch (Exception $e) {
        return 0;
    } catch (Error $e) {
        return 0;
    }
}

/**
 * Build a status badge (Bootstrap) for a pass/fail value.
 *
 * @param  string|null $value
 * @return string
 */
function pass_fail_badge($value)
{
    if ($value === 'pass') {
        return '<span class="badge bg-success">PASS</span>';
    }
    if ($value === 'fail') {
        return '<span class="badge bg-danger">FAIL</span>';
    }
    return '<span class="badge bg-secondary">N/A</span>';
}

// ---------------------------------------------------------------------------
// Password policy
// ---------------------------------------------------------------------------
define('PASSWORD_MIN_LENGTH', 6);

/**
 * Validate a new password against the system password policy.
 *
 * The policy: at least PASSWORD_MIN_LENGTH characters, at least one capital
 * letter, at least one small letter, at least one number, and no spaces.
 *
 * @param  string $password
 * @return array  Human readable problems; an empty array means the password is valid.
 */
function password_policy_errors($password)
{
    $errors   = [];
    $password = (string) $password;

    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters long.';
    }
    if (preg_match('/\s/', $password)) {
        $errors[] = 'Password must not contain spaces.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one capital letter (A-Z).';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one small letter (a-z).';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number (0-9).';
    }

    return $errors;
}

/**
 * @param  string $password
 * @return bool
 */
function password_is_valid($password)
{
    return count(password_policy_errors($password)) === 0;
}

/**
 * A short description of the policy, reused by the forms.
 *
 * @return string
 */
function password_policy_hint()
{
    return 'At least ' . PASSWORD_MIN_LENGTH . ' characters, including one capital letter (A-Z), '
        . 'one small letter (a-z) and one number (0-9). Spaces are not allowed.';
}

/**
 * Generate a random password that satisfies the policy.
 *
 * @param  int $length
 * @return string
 */
function generate_temp_password($length = 10)
{
    $length = max(8, (int) $length);
    $upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower  = 'abcdefghijkmnopqrstuvwxyz';
    $digit  = '23456789';
    $all    = $upper . $lower . $digit;

    $chars = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digit[random_int(0, strlen($digit) - 1)],
    ];
    for ($i = count($chars); $i < $length; $i++) {
        $chars[] = $all[random_int(0, strlen($all) - 1)];
    }
    shuffle($chars);

    return implode('', $chars);
}

// ---------------------------------------------------------------------------
// Request context + security / integrity auditing
// ---------------------------------------------------------------------------

/**
 * The connecting client's IP address (best-effort, direct connection only).
 *
 * @return string|null
 */
function client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null;
}

/**
 * The connecting client's user-agent, truncated to fit the column.
 *
 * @return string|null
 */
function client_user_agent()
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    return $ua !== '' ? substr($ua, 0, 255) : null;
}

/**
 * Record an administrator action in the audit trail. No-op for non-admins.
 *
 * @param string      $action
 * @param string|null $entityType
 * @param int|null    $entityId
 * @param string|null $details
 */
function audit_log($action, $entityType = null, $entityId = null, $details = null)
{
    if (!is_admin()) {
        return;
    }
    $user = current_user();
    $stmt = db()->prepare(
        'INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        isset($user['id']) ? (int) $user['id'] : null,
        substr((string) $action, 0, 60),
        $entityType !== null ? substr((string) $entityType, 0, 40) : null,
        $entityId !== null ? (int) $entityId : null,
        $details !== null ? substr((string) $details, 0, 255) : null,
        client_ip(),
    ]);
}

/**
 * Record a proctoring/integrity event raised during an examination attempt.
 *
 * @param int         $attemptId
 * @param string      $type   e.g. focus_loss, copy_attempt, context_menu
 * @param string|null $detail
 */
function log_attempt_event($attemptId, $type, $detail = null)
{
    $pdo  = db();
    $stmt = $pdo->prepare(
        'INSERT INTO attempt_events (attempt_id, event_type, detail, ip_address)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        (int) $attemptId,
        substr((string) $type, 0, 40),
        $detail !== null ? substr((string) $detail, 0, 255) : null,
        client_ip(),
    ]);

    if ($type === 'focus_loss') {
        $pdo->prepare('UPDATE exam_attempts SET focus_lost_count = focus_lost_count + 1 WHERE id = ?')
            ->execute([(int) $attemptId]);
    }
}
