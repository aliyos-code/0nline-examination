<?php
/**
 * Idempotent upgrade + test harness for the existing database.
 * - Ensures picture / share_token / explanation columns exist.
 * - Renames the single admin to Aliyu Abdussalam Abdullah (preserves id/password).
 * - Does NOT delete any existing rows.
 * Run from the project root:  php database/upgrade.php
 * Then: php database/upgrade.php --verify   (checks login flows without HTTP)
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../includes/functions.php';

$pdo = db();

function col_exists(PDO $pdo, $table, $col)
{
    $safe = preg_replace('/[^A-Za-z0-9_]/', '', $table);
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . $safe . '` LIKE ' . $pdo->quote($col));
    return (bool) $stmt->fetch();
}

function ensure_col(PDO $pdo, $table, $col, $def)
{
    if (col_exists($pdo, $table, $col)) {
        echo "  [OK] {$table}.{$col} exists\n";
        return;
    }
    $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN ' . $def);
    echo "  [OK] {$table}.{$col} added\n";
}

echo "== Upgrading schema (no data deleted) ==\n";
ensure_col($pdo, 'admins', 'picture', '`picture` VARCHAR(255) DEFAULT NULL AFTER `password`');
ensure_col($pdo, 'students', 'picture', '`picture` VARCHAR(255) DEFAULT NULL AFTER `password`');
ensure_col($pdo, 'students', 'status', "`status` ENUM('active','inactive') NOT NULL DEFAULT 'active' AFTER `department`");
ensure_col($pdo, 'examinations', 'share_token', '`share_token` VARCHAR(64) DEFAULT NULL AFTER `max_attempts`');
ensure_col($pdo, 'questions', 'explanation', '`explanation` TEXT DEFAULT NULL AFTER `correct_option`');
ensure_col($pdo, 'questions', 'difficulty', "`difficulty` ENUM('easy','medium','difficult') NOT NULL DEFAULT 'medium' AFTER `explanation`");
ensure_col($pdo, 'questions', 'category', '`category` VARCHAR(100) DEFAULT NULL AFTER `difficulty`');

// New tables: system settings + per-student examination assignments.
$pdo->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
    `setting_key` VARCHAR(64) NOT NULL PRIMARY KEY,
    `setting_value` VARCHAR(255) DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  [OK] system_settings table ready\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS `exam_assignments` (
    `examination_id` INT NOT NULL,
    `student_id` INT NOT NULL,
    `assigned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`examination_id`, `student_id`),
    KEY `idx_assign_student` (`student_id`),
    CONSTRAINT `fk_assign_exam` FOREIGN KEY (`examination_id`) REFERENCES `examinations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_assign_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "  [OK] exam_assignments table ready\n";

// Examination visibility: allow 'closed' status (auto-close at closing time).
$pdo->exec("ALTER TABLE examinations MODIFY status ENUM('active','inactive','closed') NOT NULL DEFAULT 'inactive'");
echo "  [OK] examinations.status enum includes closed\n";

// indexes
$idx = $pdo->query("SHOW INDEX FROM students WHERE Key_name = 'idx_student_name'")->fetch();
if (!$idx) { $pdo->exec('ALTER TABLE students ADD KEY idx_student_name (full_name)'); echo "  [OK] students idx_student_name added\n"; }
$idx = $pdo->query("SHOW INDEX FROM admins WHERE Key_name = 'idx_admin_name'")->fetch();
if (!$idx) { $pdo->exec('ALTER TABLE admins ADD KEY idx_admin_name (full_name)'); echo "  [OK] admins idx_admin_name added\n"; }
$idx = $pdo->query("SHOW INDEX FROM examinations WHERE Key_name = 'uniq_exam_share'")->fetch();
if (!$idx) { $pdo->exec('ALTER TABLE examinations ADD UNIQUE KEY uniq_exam_share (share_token)'); echo "  [OK] examinations uniq_exam_share added\n"; }

// Authorized admin
$targetName = 'Aliyu Abdussalam Abdullah';
$rows = $pdo->query('SELECT id, full_name FROM admins ORDER BY id ASC')->fetchAll();
$hasTarget = false;
foreach ($rows as $r) {
    if (normalize_full_name($r['full_name']) === normalize_full_name($targetName)) { $hasTarget = true; }
}
if ($hasTarget) {
    echo "  [OK] admin {$targetName} already present\n";
} elseif (count($rows) === 1) {
    $pdo->prepare('UPDATE admins SET full_name = ? WHERE id = ?')->execute(array($targetName, $rows[0]['id']));
    echo "  [OK] single admin renamed to {$targetName} (id {$rows[0]['id']}, password preserved)\n";
} else {
    $pdo->prepare('INSERT INTO admins (full_name, email, password) VALUES (?, ?, ?)')
        ->execute(array($targetName, 'admin@example.com', password_hash('admin123', PASSWORD_DEFAULT)));
    echo "  [OK] inserted admin {$targetName} (default password: admin123 — change it after login)\n";
}

echo "\n== Counts (unchanged data) ==\n";
foreach (array('admins','students','examinations','questions','exam_attempts') as $t) {
    echo "  {$t}: " . $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn() . "\n";
}

if (isset($_SERVER['argv']) && is_array($_SERVER['argv']) && in_array('--verify', $_SERVER['argv'], true)) {
    echo "\n== Verify: full-name matching ==\n";
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE LOWER(TRIM(full_name)) = ? LIMIT 1');
    $stmt->execute(array(normalize_full_name('Aliyu Abdussalam Abdullah')));
    $a = $stmt->fetch();
    echo $a ? "  [OK] admin found by full name: {$a['full_name']}\n" : "  [FAIL] admin not found\n";
    var_dump($a ? password_verify('admin123', $a['password']) : false);
    echo $a && password_verify('admin123', $a['password']) ? "  [OK] admin password admin123 verifies\n" : "  [WARN] admin123 does not verify (admin may have changed password)\n";
    echo "openai configured: " . (function_exists('openai_is_configured') ? (openai_is_configured() ? 'yes' : 'no') : 'n/a') . "\n";
}

echo "Done.\n";
