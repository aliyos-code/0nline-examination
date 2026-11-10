-- ============================================================================
-- ONLINE EXAMINATION AND RESULT MANAGEMENT SYSTEM
-- Database schema + seed data
-- Import this file through phpMyAdmin (Import tab) or the MySQL CLI:
--     mysql -u root -p < database.sql
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `online_exam_system`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `online_exam_system`;

-- Drop in reverse dependency order so the script can be re-run safely.
DROP TABLE IF EXISTS `exam_assignments`;
DROP TABLE IF EXISTS `system_settings`;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `attempt_events`;
DROP TABLE IF EXISTS `student_answers`;
DROP TABLE IF EXISTS `attempt_questions`;
DROP TABLE IF EXISTS `exam_attempts`;
DROP TABLE IF EXISTS `questions`;
DROP TABLE IF EXISTS `examinations`;
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `announcements`;
DROP TABLE IF EXISTS `admins`;

-- ----------------------------------------------------------------------------
-- Administrators
-- ----------------------------------------------------------------------------
CREATE TABLE `admins` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `full_name`  VARCHAR(150) NOT NULL,
    `email`      VARCHAR(150) NOT NULL,
    `password`   VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Students
-- ----------------------------------------------------------------------------
CREATE TABLE `students` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `reg_number`  VARCHAR(50)  NOT NULL,
    `full_name`   VARCHAR(150) NOT NULL,
    `email`       VARCHAR(150) NOT NULL,
    `password`    VARCHAR(255) NOT NULL,
    `department`  VARCHAR(150) DEFAULT NULL,
    `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_student_email` (`email`),
    UNIQUE KEY `uniq_student_reg` (`reg_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Examinations
-- ----------------------------------------------------------------------------
CREATE TABLE `examinations` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `title`            VARCHAR(200) NOT NULL,
    `course_name`      VARCHAR(200) NOT NULL,
    `course_code`      VARCHAR(50)  NOT NULL,
    `instructions`     TEXT,
    `duration_minutes` INT NOT NULL DEFAULT 30,
    `total_questions`  INT NOT NULL DEFAULT 0,
    `pass_mark`        INT NOT NULL DEFAULT 50,
    `status`           ENUM('active','inactive','closed') NOT NULL DEFAULT 'inactive',
    -- Scheduling window (NULL = no restriction on that side) and the number of
    -- attempts a student is allowed. max_attempts defaults to 1.
    `available_from`   DATETIME DEFAULT NULL,
    `available_until`  DATETIME DEFAULT NULL,
    `max_attempts`     INT NOT NULL DEFAULT 1,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_exam_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Questions (multiple choice)
-- ----------------------------------------------------------------------------
CREATE TABLE `questions` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `examination_id`   INT NOT NULL,
    `question_text`    TEXT NOT NULL,
    `option_a`         VARCHAR(500) NOT NULL,
    `option_b`         VARCHAR(500) NOT NULL,
    `option_c`         VARCHAR(500) NOT NULL,
    `option_d`         VARCHAR(500) NOT NULL,
    `correct_option`   ENUM('A','B','C','D') NOT NULL,
    `difficulty`       ENUM('easy','medium','difficult') NOT NULL DEFAULT 'medium',
    `category`         VARCHAR(100) DEFAULT NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_questions_exam`
        FOREIGN KEY (`examination_id`) REFERENCES `examinations` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    KEY `idx_questions_exam` (`examination_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Examination attempts (a student may have several, bounded by the parent
-- examination's max_attempts). start_time / deadline are stored server-side
-- to secure the timer.
-- ----------------------------------------------------------------------------
CREATE TABLE `exam_attempts` (
    `id`                INT AUTO_INCREMENT PRIMARY KEY,
    `student_id`        INT NOT NULL,
    `examination_id`    INT NOT NULL,
    `start_time`        DATETIME NOT NULL,
    `deadline`          DATETIME NOT NULL,
    `end_time`          DATETIME DEFAULT NULL,
    `score`             INT DEFAULT NULL,
    `total_questions`   INT NOT NULL DEFAULT 0,
    `correct_answers`   INT DEFAULT NULL,
    `wrong_answers`     INT DEFAULT NULL,
    `percentage`        DECIMAL(5,2) DEFAULT NULL,
    `pass_fail`         ENUM('pass','fail') DEFAULT NULL,
    `status`            ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
    `ip_address`        VARCHAR(45)  DEFAULT NULL,
    `user_agent`        VARCHAR(255) DEFAULT NULL,
    `focus_lost_count`  INT NOT NULL DEFAULT 0,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_attempt_student`
        FOREIGN KEY (`student_id`) REFERENCES `students` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_attempt_exam`
        FOREIGN KEY (`examination_id`) REFERENCES `examinations` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    KEY `idx_student_exam` (`student_id`, `examination_id`),
    KEY `idx_attempt_exam` (`examination_id`),
    KEY `idx_attempt_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Questions assigned to an attempt (randomized order + option order)
-- option_order stores the display order of the original option letters,
-- e.g. "C,A,D,B" means display C first, then A, then D, then B.
-- ----------------------------------------------------------------------------
CREATE TABLE `attempt_questions` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `attempt_id`    INT NOT NULL,
    `question_id`   INT NOT NULL,
    `display_order` INT NOT NULL,
    `option_order`  VARCHAR(15) NOT NULL DEFAULT 'A,B,C,D',
    CONSTRAINT `fk_aq_attempt`
        FOREIGN KEY (`attempt_id`) REFERENCES `exam_attempts` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_aq_question`
        FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `uniq_attempt_question` (`attempt_id`, `question_id`),
    KEY `idx_aq_attempt` (`attempt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Student answers (selected_option uses the ORIGINAL option letter)
-- ----------------------------------------------------------------------------
CREATE TABLE `student_answers` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `attempt_id`      INT NOT NULL,
    `question_id`     INT NOT NULL,
    `selected_option` ENUM('A','B','C','D') DEFAULT NULL,
    `is_correct`      TINYINT(1) DEFAULT NULL,
    `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                      ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_sa_attempt`
        FOREIGN KEY (`attempt_id`) REFERENCES `exam_attempts` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_sa_question`
        FOREIGN KEY (`question_id`) REFERENCES `questions` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    UNIQUE KEY `uniq_attempt_answer` (`attempt_id`, `question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Proctoring / integrity events raised during an attempt
-- (focus loss, visibility change, copy attempts, ...). Surfaced to admins.
-- ----------------------------------------------------------------------------
CREATE TABLE `attempt_events` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `attempt_id`  INT NOT NULL,
    `event_type`  VARCHAR(40) NOT NULL,
    `detail`      VARCHAR(255) DEFAULT NULL,
    `ip_address`  VARCHAR(45)  DEFAULT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_event_attempt`
        FOREIGN KEY (`attempt_id`) REFERENCES `exam_attempts` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    KEY `idx_event_attempt` (`attempt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Login attempts: powers brute-force throttling and a security audit trail.
-- ----------------------------------------------------------------------------
CREATE TABLE `login_attempts` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `email`        VARCHAR(150) DEFAULT NULL,
    `role`         VARCHAR(20)  DEFAULT NULL,
    `ip_address`   VARCHAR(45)  DEFAULT NULL,
    `success`      TINYINT(1) NOT NULL DEFAULT 0,
    `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_la_email_time` (`email`, `attempted_at`),
    KEY `idx_la_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Administrator audit trail: who changed what, when and from where.
-- ----------------------------------------------------------------------------
CREATE TABLE `audit_logs` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id`    INT DEFAULT NULL,
    `action`      VARCHAR(60) NOT NULL,
    `entity_type` VARCHAR(40) DEFAULT NULL,
    `entity_id`   INT DEFAULT NULL,
    `details`     VARCHAR(255) DEFAULT NULL,
    `ip_address`  VARCHAR(45) DEFAULT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_audit_admin` (`admin_id`),
    KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Announcements / notices posted by the administrator for the students.
-- ----------------------------------------------------------------------------
CREATE TABLE `announcements` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `title`      VARCHAR(200) NOT NULL,
    `body`       TEXT NOT NULL,
    `audience`   ENUM('all','students') NOT NULL DEFAULT 'students',
    `created_by` INT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ann_created` (`created_at`),
    CONSTRAINT `fk_ann_admin`
        FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- System settings (registration-number format, etc.) and per-student
-- examination assignments (no assignments = available to all students).
-- ----------------------------------------------------------------------------
CREATE TABLE `system_settings` (
    `setting_key`   VARCHAR(64) NOT NULL PRIMARY KEY,
    `setting_value` VARCHAR(255) DEFAULT NULL,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `exam_assignments` (
    `examination_id` INT NOT NULL,
    `student_id`     INT NOT NULL,
    `assigned_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`examination_id`, `student_id`),
    KEY `idx_assign_student` (`student_id`),
    CONSTRAINT `fk_assign_exam` FOREIGN KEY (`examination_id`) REFERENCES `examinations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_assign_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SEED / DEMO DATA
-- Default admin login:
--     Email:    admin@example.com
--     Password: admin123
-- Demo student login:
--     Email:    student@example.com
--     Password: student123
-- (These demo accounts pre-date the password policy, which applies to every
--  new or changed password. Remove or change them before real deployment.)
-- ============================================================================

INSERT INTO `admins` (`full_name`, `email`, `password`) VALUES
('System Administrator', 'admin@example.com',
 '$2y$10$lkKoDQXp8D7dGXWmiEZLaOev9TQiKnq1NWU5CEZj7gS6ZuPdCSymS');

INSERT INTO `students` (`reg_number`, `full_name`, `email`, `password`, `department`) VALUES
('STU001', 'Demo Student', 'student@example.com',
 '$2y$10$YyPIo.BgDrnpP8F4WmUJsuADnEDpamSCg.DrnJtA.WrxoZkmsfIzq', 'Computer Science');

-- Sample examination (inactive by default so the admin can review it first)
INSERT INTO `examinations`
    (`title`, `course_name`, `course_code`, `instructions`,
     `duration_minutes`, `total_questions`, `pass_mark`, `status`)
VALUES
('Introduction to Computing - Midterm Test',
 'Introduction to Computing',
 'CSC101',
 'Answer all questions. Each question carries one mark. You may move back and forth between questions before submitting. The examination will submit automatically when the time expires.',
 30, 5, 50, 'active');

SET @exam_id = LAST_INSERT_ID();

INSERT INTO `questions`
    (`examination_id`, `question_text`, `option_a`, `option_b`, `option_c`, `option_d`, `correct_option`)
VALUES
(@exam_id, 'Which of the following is an input device?', 'Monitor', 'Keyboard', 'Speaker', 'Projector', 'B'),
(@exam_id, 'What does CPU stand for?', 'Central Processing Unit', 'Computer Personal Unit', 'Central Program Utility', 'Control Processing Unicorn', 'A'),
(@exam_id, 'Which of these is an example of system software?', 'Microsoft Word', 'Google Chrome', 'Windows Operating System', 'Photoshop', 'C'),
(@exam_id, 'How many bits are in one byte?', '4', '8', '16', '32', 'B'),
 (@exam_id, 'Which storage device has no moving parts?', 'Hard Disk Drive', 'Floppy Disk', 'Solid State Drive', 'Optical Disc', 'C');

-- Sample announcement shown on the student dashboard and the public notice board
INSERT INTO `announcements` (`title`, `body`, `audience`, `created_by`) VALUES
('Welcome to the examination portal',
 'Use the portal to read your examination instructions, sit your papers and download your result sheets. Remember: your password must contain a capital letter, a small letter and a number.',
 'students', 1);
