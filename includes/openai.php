<?php
/**
 * Official OpenAI API integration — SERVER-SIDE ONLY.
 *
 * The API key is read from the environment (OPENAI_API_KEY) via
 * config/env.php / .env. It is NEVER printed to HTML/JS.
 *
 * If no key is configured, callers fall back to the offline AI
 * (includes/ai.php) so the system keeps working without internet.
 *
 * Required PHP extensions: curl, json (standard).
 */

require_once __DIR__ . '/../config/env.php';
if (!function_exists('ai_validate_question')) {
    require_once __DIR__ . '/ai.php';
}

function openai_is_configured()
{
    $key = env('OPENAI_API_KEY', '');
    return is_string($key) && trim($key) !== '';
}

function openai_model()
{
    $m = trim((string) env('OPENAI_MODEL', 'gpt-4o-mini'));
    return $m !== '' ? $m : 'gpt-4o-mini';
}

function openai_base_url()
{
    $b = trim((string) env('OPENAI_API_BASE', 'https://api.openai.com/v1'));
    return rtrim($b !== '' ? $b : 'https://api.openai.com/v1', '/');
}

/**
 * Generate difficult multiple-choice questions with OpenAI.
 *
 * @param string $topic
 * @param int    $count 1..20 (kept small to control cost/latency)
 * @param string $difficulty 'difficult'|'moderate'|'easy' (default difficult)
 * @param string $courseName optional context
 * @param string $courseCode optional context
 * @return array ['ok'=>bool,'questions'=>array,'error'=>string|null]
 */
function openai_generate_questions($topic, $count, $difficulty = 'difficult', $courseName = '', $courseCode = '', $exclude = array(), $academicLevel = 'university')
{
    $count = max(1, min(20, (int) $count));
    $difficulty = in_array($difficulty, array('difficult', 'moderate', 'easy'), true) ? $difficulty : 'difficult';
    $academicLevel = function_exists('ai_sanitise_level') ? ai_sanitise_level($academicLevel) : 'university';
    $topic = trim((string) $topic);
    if ($topic === '') {
        return array('ok' => false, 'questions' => array(), 'error' => 'Please enter a topic for OpenAI generation.', 'rejected' => 0);
    }
    if (!openai_is_configured()) {
        return array('ok' => false, 'questions' => array(), 'error' => 'OpenAI API key is not configured. Set OPENAI_API_KEY in the server environment or .env file. The offline AI generator is still available.', 'rejected' => 0);
    }
    if (!function_exists('curl_init')) {
        return array('ok' => false, 'questions' => array(), 'error' => 'cURL is not available on this server, so the OpenAI API cannot be called.', 'rejected' => 0);
    }

    $context = trim($courseName . ' ' . $courseCode);
    $levelLabel = $academicLevel === 'postgraduate' ? 'postgraduate' : ($academicLevel === 'professional' ? 'professional' : 'university undergraduate');
    $system = 'You are an expert university examination writer. Return ONLY valid JSON. No markdown, no commentary, no introduction, no explanations, no motivational messages.';
    $userLines = array();
    $userLines[] = 'Create ' . $count . ' ' . $difficulty . ' multiple-choice examination questions (A-D) for ' . $levelLabel . ' level about: ' . $topic;
    if ($context !== '') {
        $userLines[] = 'Course context: ' . $context;
    }
    $userLines[] = 'Difficulty: ' . $difficulty . '. Questions must be challenging and intellectually demanding: test deep understanding, critical thinking, application, analysis, interpretation and problem-solving. Avoid basic recall, childish, obvious or overly simple questions. Match the depth to the course, subject and ' . $levelLabel . ' level.';
    $userLines[] = 'Professional quality: use clear, academically correct language. Every question must be a complete, unambiguous university examination question with exactly one correct answer. Vary the question structures and test a different concept or learning objective in every question. Use scenario, case, calculation, comparison and interpretation questions where appropriate.';
    $userLines[] = 'Each question must have: question_text (string ending with "?"), option_a, option_b, option_c, option_d (strings), correct_option (one of "A","B","C","D"). Options must be plausible, distinct, similar in length, and free of "all of the above" / "none of the above". Distribute the correct letter across the batch.';
    if (is_array($exclude) && $exclude) {
        $userLines[] = 'CRITICAL - do NOT repeat, paraphrase, or slightly modify any of these existing questions. Each new question must test a different concept from them:';
        $n = 0;
        foreach ($exclude as $ex) {
            $txt = is_array($ex) ? (isset($ex['question_text']) ? $ex['question_text'] : '') : (string) $ex;
            $txt = trim($txt);
            if ($txt === '') { continue; }
            $userLines[] = '- ' . substr($txt, 0, 300);
            if (++$n >= 50) { break; }
        }
    }
    $userLines[] = 'Return ONLY this JSON object, with no text before or after it: {"questions":[{...}, ...]}';
    $userPrompt = implode("\n", $userLines);

    $payload = array(
        'model' => openai_model(),
        'temperature' => 0.7,
        'messages' => array(
            array('role' => 'system', 'content' => $system),
            array('role' => 'user', 'content' => $userPrompt),
        ),
    );

    $url = openai_base_url() . '/chat/completions';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . env('OPENAI_API_KEY', ''),
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $curlErr !== '') {
        return array('ok' => false, 'questions' => array(), 'error' => 'Could not reach the OpenAI API. Check the server connection and try again.', 'rejected' => 0);
    }
    if ($status < 200 || $status >= 300) {
        // Do not leak key details; surface a generic message + status.
        return array('ok' => false, 'questions' => array(), 'error' => 'OpenAI API returned HTTP ' . $status . '. Check the API key, model name, and quota, then try again.', 'rejected' => 0);
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
        return array('ok' => false, 'questions' => array(), 'error' => 'OpenAI returned an unexpected response. Please try again.', 'rejected' => 0);
    }
    $content = trim((string) $data['choices'][0]['message']['content']);
    // Strip markdown fences / surrounding commentary if present.
    if (strpos($content, '```') !== false) {
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/```\s*$/', '', $content);
    }
    // Extract the JSON object if any commentary surrounds it.
    $first = strpos($content, '{');
    $last = strrpos($content, '}');
    if ($first !== false && $last !== false && $last > $first) {
        $content = substr($content, $first, $last - $first + 1);
    }
    $parsed = json_decode($content, true);
    if (!is_array($parsed) || !isset($parsed['questions']) || !is_array($parsed['questions'])) {
        return array('ok' => false, 'questions' => array(), 'error' => 'OpenAI did not return questions in the expected JSON format. Please try again or use the offline AI.', 'rejected' => 0);
    }
    $clean = array();
    $letters = array('A', 'B', 'C', 'D');
    foreach ($parsed['questions'] as $q) {
        if (!is_array($q)) {
            continue;
        }
        $text = isset($q['question_text']) ? trim((string) $q['question_text']) : '';
        $a = isset($q['option_a']) ? trim((string) $q['option_a']) : '';
        $b = isset($q['option_b']) ? trim((string) $q['option_b']) : '';
        $c = isset($q['option_c']) ? trim((string) $q['option_c']) : '';
        $d = isset($q['option_d']) ? trim((string) $q['option_d']) : '';
        $correct = strtoupper(isset($q['correct_option']) ? trim((string) $q['correct_option']) : '');
        if ($text === '' || $a === '' || $b === '' || $c === '' || $d === '') {
            continue;
        }
        if (!in_array($correct, $letters, true)) {
            continue;
        }
        $clean[] = array(
            'question_text' => substr($text, 0, 4000),
            'option_a' => substr($a, 0, 500),
            'option_b' => substr($b, 0, 500),
            'option_c' => substr($c, 0, 500),
            'option_d' => substr($d, 0, 500),
            'correct_option' => $correct,
        );
        if (count($clean) >= $count) {
            break;
        }
    }
    if (!$clean) {
        return array('ok' => false, 'questions' => array(), 'error' => 'OpenAI returned no usable questions. Please try again.', 'rejected' => 0);
    }
    // Database/AI validation: reject invalid, duplicate (vs existing
    // questions and within the batch) items. The caller tops up the
    // shortfall with the offline engine - duplicates are never kept.
    $rejected = 0;
    if (function_exists('ai_deduplicate_questions')) {
        $filtered = ai_deduplicate_questions($clean, is_array($exclude) ? $exclude : array(), 0.55);
        $clean = $filtered['kept'];
        $rejected = count($filtered['rejected']);
        if (!$clean) {
            return array('ok' => false, 'questions' => array(), 'error' => 'OpenAI returned only duplicate or invalid questions, which were rejected. The offline generator will be used instead.', 'rejected' => $rejected);
        }
    }
    return array('ok' => true, 'questions' => $clean, 'error' => null, 'rejected' => $rejected);
}

/**
 * Hybrid generator: OpenAI first (when configured) for novel difficult
 * questions, offline engine as fallback and top-up, always filtered
 * against $exclude so the requested count is met without duplicates.
 *
 * @return array ['questions'=>array,'source'=>'openai'|'offline'|'hybrid','rejected'=>int,'error'=>string|null]
 */
function openai_generate_questions_with_fallback($topic, $count, $type = 'mcq', $courseName = '', $courseCode = '', $exclude = array(), $difficulty = 'difficult', $academicLevel = 'university', $seed = 0)
{
    $count = max(1, min(100, (int) $count));
    if ($type !== 'true_false' && $type !== 'mixed') { $type = 'mcq'; }
    $difficulty = function_exists('ai_sanitise_difficulty') ? ai_sanitise_difficulty($difficulty) : 'difficult';
    $academicLevel = function_exists('ai_sanitise_level') ? ai_sanitise_level($academicLevel) : 'university';
    if ($seed <= 0) { $seed = random_int(1, 1000000000); }

    $questions = array();
    $source = 'offline';
    $error = null;
    $rejected = 0;

    if ($type !== 'true_false' && openai_is_configured()) {
        $mcqCount = $type === 'mixed' ? $count - (int) ceil($count / 2) : $count;
        if ($mcqCount > 0) {
            $res = openai_generate_questions($topic, $mcqCount, $difficulty, $courseName, $courseCode, $exclude, $academicLevel);
            $rejected += isset($res['rejected']) ? (int) $res['rejected'] : 0;
            if (!empty($res['ok'])) {
                $questions = $res['questions'];
                $source = ($type === 'mixed') ? 'hybrid' : 'openai';
            } else {
                $error = isset($res['error']) ? $res['error'] : null;
            }
        }
    } elseif ($type !== 'true_false' && !openai_is_configured()) {
        $error = null; // Offline is the intended path; not shown as an error.
    }

    $avoid = array_merge(is_array($exclude) ? $exclude : array(), array_map(function ($q) { return $q['question_text']; }, $questions));
    // True/False portion for mixed, or top-up for all types.
    $needed = $count - count($questions);
    if ($needed > 0) {
        $topupType = $type;
        if ($type === 'mixed' && $questions) {
            // Questions so far are the MCQ portion; top up with TF only.
            $topupType = 'true_false';
        }
        if ($type === 'mixed' && !$questions) {
            $topupType = 'mixed';
        }
        $topped = ai_generate_questions($topic, $needed, $topupType, $seed + 17, $avoid, $difficulty, $academicLevel);
        if ($topped) {
            $questions = array_merge($questions, $topped);
            $source = ($source === 'openai' || $source === 'hybrid') ? 'hybrid' : 'offline';
        }
    }

    // Final validation pass (cheap insurance; never return duplicates).
    if (function_exists('ai_deduplicate_questions')) {
        $filtered = ai_deduplicate_questions($questions, is_array($exclude) ? $exclude : array(), 0.55);
        $rejected += count($filtered['rejected']);
        $questions = $filtered['kept'];
    }
    return array('questions' => array_slice($questions, 0, $count), 'source' => $source, 'rejected' => $rejected, 'error' => $error);
}
