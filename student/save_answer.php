<?php
/**
 * Student: AJAX endpoint that saves a single answer during an examination.
 *
 * Returns JSON. Never reveals the correct answer.
 */
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

function json_out($data)
{
    echo json_encode($data);
    exit;
}

if (!is_student()) {
    http_response_code(403);
    json_out(['status' => 'error', 'message' => 'Not authorized.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    json_out(['status' => 'error', 'message' => 'Method not allowed.']);
}

if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    http_response_code(419);
    json_out(['status' => 'error', 'message' => 'Session expired.']);
}

$studentId  = (int) current_user()['id'];
$attemptId  = (int) input('attempt_id');
$questionId = (int) input('question_id');
$option     = strtoupper(input('option'));

if (!in_array($option, ['A', 'B', 'C', 'D'], true)) {
    json_out(['status' => 'error', 'message' => 'Invalid option.']);
}

$pdo = db();

// The attempt must belong to this student and still be in progress.
$stmt = $pdo->prepare('SELECT * FROM exam_attempts WHERE id = ? AND student_id = ? LIMIT 1');
$stmt->execute([$attemptId, $studentId]);
$attempt = $stmt->fetch();

if (!$attempt || $attempt['status'] !== 'in_progress') {
    json_out(['status' => 'error', 'message' => 'Attempt is not active.']);
}

// Server-side timing guard.
if (strtotime($attempt['deadline']) <= time()) {
    finalize_attempt($attemptId);
    json_out(['status' => 'expired', 'message' => 'Time expired.']);
}

// The question must belong to this attempt.
$stmt = $pdo->prepare('SELECT id FROM attempt_questions WHERE attempt_id = ? AND question_id = ? LIMIT 1');
$stmt->execute([$attemptId, $questionId]);
if (!$stmt->fetch()) {
    json_out(['status' => 'error', 'message' => 'Question not part of this attempt.']);
}

$stmt = $pdo->prepare(
    'INSERT INTO student_answers (attempt_id, question_id, selected_option)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE selected_option = VALUES(selected_option)'
);
$stmt->execute([$attemptId, $questionId, $option]);

json_out(['status' => 'ok']);
