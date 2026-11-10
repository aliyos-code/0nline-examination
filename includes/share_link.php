<?php
/**
 * Shareable examination links (server-side only).
 *
 * Each examination may carry a random share token. The shareable URL
 * points at student/exam_link.php?share=TOKEN which, after student
 * login, forwards to that examination's instructions/start page.
 * Tokens are random and never expose the numeric exam id requirement.
 */

require_once __DIR__ . '/functions.php';

function generate_share_token()
{
    try {
        return bin2hex(random_bytes(16)); // 32 hex chars
    } catch (Exception $e) {
        return sha1(uniqid('', true));
    }
}

function exam_share_url($exam)
{
    $token = isset($exam['share_token']) ? trim((string) $exam['share_token']) : '';
    if ($token === '') {
        return null;
    }
    return url('student/exam_link.php?share=' . rawurlencode($token));
}

function ensure_exam_share_token(PDO $pdo, $examId)
{
    $stmt = $pdo->prepare('SELECT share_token FROM examinations WHERE id = ?');
    $stmt->execute(array((int) $examId));
    $token = $stmt->fetchColumn();
    if ($token === false || $token === null || trim((string) $token) === '') {
        $token = generate_share_token();
        $pdo->prepare('UPDATE examinations SET share_token = ? WHERE id = ?')->execute(array($token, (int) $examId));
    }
    return $token;
}
