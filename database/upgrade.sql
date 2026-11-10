-- ==========================================================================
-- ONLINE EXAMINATION AND RESULT MANAGEMENT SYSTEM — UPGRADE MIGRATION
-- Safe to run repeatedly. It NEVER deletes existing rows:
--   * adds `picture` to admins/students (if missing)
--   * adds `share_token` + `explanation` columns (if missing)
--   * renames the single admin to Aliyu Abdussalam Abdullah (if needed)
--   * leaves every examination, question, student and result untouched.
--
-- Import via phpMyAdmin (Import tab) or: mysql -u root -p < upgrade.sql
-- ==========================================================================

USE `online_exam_system`;

-- --- 1. admins.picture ------------------------------------------------------
SET @has_admins_pic = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'picture'
);
SET @sql = IF(@has_admins_pic = 0,
  'ALTER TABLE `admins` ADD COLUMN `picture` VARCHAR(255) DEFAULT NULL AFTER `password`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 2. students.picture + normalized-name index -----------------------------
SET @has_students_pic = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'picture'
);
SET @sql = IF(@has_students_pic = 0,
  'ALTER TABLE `students` ADD COLUMN `picture` VARCHAR(255) DEFAULT NULL AFTER `password`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_student_name_idx = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND INDEX_NAME = 'idx_student_name'
);
SET @sql = IF(@has_student_name_idx = 0,
  'ALTER TABLE `students` ADD KEY `idx_student_name` (`full_name`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_admin_name_idx = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND INDEX_NAME = 'idx_admin_name'
);
SET @sql = IF(@has_admin_name_idx = 0,
  'ALTER TABLE `admins` ADD KEY `idx_admin_name` (`full_name`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 3. examinations.share_token ---------------------------------------------
SET @has_share = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'examinations' AND COLUMN_NAME = 'share_token'
);
SET @sql = IF(@has_share = 0,
  'ALTER TABLE `examinations` ADD COLUMN `share_token` VARCHAR(64) DEFAULT NULL AFTER `max_attempts`, ADD UNIQUE KEY `uniq_exam_share` (`share_token`)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 4. questions.explanation (for AI answers with explanations) --------------
SET @has_expl = (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'questions' AND COLUMN_NAME = 'explanation'
);
SET @sql = IF(@has_expl = 0,
  'ALTER TABLE `questions` ADD COLUMN `explanation` TEXT DEFAULT NULL AFTER `correct_option`',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 5. Authorized admin: Aliyu Abdussalam Abdullah ---------------------------
-- If an admin with that exact name already exists, leave everything alone.
-- Else, if there is exactly one admin row, rename it (preserving id/password).
-- Else, insert a fresh admin row (default password admin123 — change after login).
SET @admin_target = (SELECT COUNT(*) FROM `admins` WHERE LOWER(TRIM(full_name)) = 'aliyu abdussalam abdullah');
SET @admin_count  = (SELECT COUNT(*) FROM `admins`);

SET @sql = IF(@admin_target = 0 AND @admin_count = 1,
  'UPDATE `admins` SET full_name = ''Aliyu Abdussalam Abdullah'' ORDER BY id ASC LIMIT 1',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @admin_target2 = (SELECT COUNT(*) FROM `admins` WHERE LOWER(TRIM(full_name)) = 'aliyu abdussalam abdullah');
SET @sql = IF(@admin_target2 = 0,
  'INSERT INTO `admins` (`full_name`, `email`, `password`) VALUES (''Aliyu Abdussalam Abdullah'', ''admin@example.com'', ''$2y$10$lkKoDQXp8D7dGXWmiEZLaOev9TQiKnq1NWU5CEZj7gS6ZuPdCSymS'')',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- 6. Verify ----------------------------------------------------------------
SELECT 'admins' AS tbl, COUNT(*) AS rows FROM `admins`
UNION ALL SELECT 'students', COUNT(*) FROM `students`
UNION ALL SELECT 'examinations', COUNT(*) FROM `examinations`
UNION ALL SELECT 'questions', COUNT(*) FROM `questions`;

SELECT id, full_name, email, picture FROM `admins` ORDER BY id ASC;
