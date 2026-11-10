<?php
/**
 * Student: AJAX endpoint that records a proctoring/integrity event during an
 * examination (focus loss, copy attempt, ...). Returns JSON and never reveals
 * examination content.
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

$studentId = (int) current_user()['id'];
$attemptId = (int) input('attempt_id');
$eventType = input('event_type');

$allowed = ['focus_loss', 'copy_attempt', 'paste_attempt', 'context_menu', 'fullscreen_exit'];
if (!in_array($eventType, $allowed, true)) {
    json_out(['status' => 'error', 'message' => 'Unknown event.']);
}

$pdo  = db();
$stmt = $pdo->prepare('SELECT id FROM exam_attempts WHERE id = ? AND student_id = ? AND status = ? LIMIT 1');
$stmt->execute([$attemptId, $studentId, 'in_progress']);
if (!$stmt->fetch()) {
    json_out(['status' => 'error', 'message' => 'Attempt is not active.']);
}

log_attempt_event($attemptId, $eventType, input('detail'));
json_out(['status' => 'ok']);
