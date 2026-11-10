<?php
/**
 * Student: submit an examination, save answers and compute the result.
 */
require_once __DIR__ . '/../includes/auth.php';
require_student();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('student/examinations.php');
}

$pdo       = db();
$studentId = (int) current_user()['id'];

if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    set_flash('danger', 'Your session expired. Please submit again.');
    redirect('student/take_exam.php');
}

$attemptId = (int) input('attempt_id');

$stmt = $pdo->prepare('SELECT * FROM exam_attempts WHERE id = ? AND student_id = ? LIMIT 1');
$stmt->execute([$attemptId, $studentId]);
$attempt = $stmt->fetch();

if (!$attempt) {
    unset($_SESSION['attempt_id']);
    set_flash('danger', 'Invalid examination attempt.');
    redirect('student/examinations.php');
}

// Already submitted - just show the result (prevents duplicate submission).
if ($attempt['status'] === 'completed') {
    unset($_SESSION['attempt_id']);
    redirect('student/results.php?id=' . $attemptId);
}

// Only persist freshly posted answers while (approximately) within the time
// limit. A 5-second grace period covers network latency on an honest submit.
$withinTime = (strtotime($attempt['deadline']) + 5) >= time();

if ($withinTime && isset($_POST['answers']) && is_array($_POST['answers'])) {
    // Confirm which questions belong to this attempt.
    $stmt = $pdo->prepare('SELECT question_id FROM attempt_questions WHERE attempt_id = ?');
    $stmt->execute([$attemptId]);
    $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $validSet = array_flip($validIds);

    $upsert = $pdo->prepare(
        'INSERT INTO student_answers (attempt_id, question_id, selected_option)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE selected_option = VALUES(selected_option)'
    );

    foreach ($_POST['answers'] as $qid => $option) {
        $qid    = (int) $qid;
        $option = strtoupper((string) $option);
        if (isset($validSet[$qid]) && in_array($option, ['A', 'B', 'C', 'D'], true)) {
            $upsert->execute([$attemptId, $qid, $option]);
        }
    }
}

finalize_attempt($attemptId);
unset($_SESSION['attempt_id']);

set_flash('success', 'Your examination has been submitted and marked.');
redirect('student/results.php?id=' . $attemptId);
