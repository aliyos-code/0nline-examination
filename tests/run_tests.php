<?php
/**
 * Lightweight test suite for the database-free logic of the system.
 *
 * Run from the project root:
 *     php tests/run_tests.php
 *
 * These tests cover the helpers that do not need MySQL: the password policy,
 * output escaping, CSRF tokens, examination windows, the AI similarity helpers
 * and the CSV writer.
 */
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/csv.php';

// ---------------------------------------------------------------------------
// Tiny test harness
// ---------------------------------------------------------------------------
$passed = 0;
$failed = 0;
$failures = [];

function test(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $failures;

    if ($condition) {
        $passed++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed++;
        $failures[] = $name . ($detail !== '' ? ' -- ' . $detail : '');
        echo "  [FAIL] {$name}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n{$title}\n";
}

// ---------------------------------------------------------------------------
// Password policy
// ---------------------------------------------------------------------------
section('Password policy');

test('rejects a password that is too short',
    password_policy_errors('Ab1') !== []);

test('rejects a password without a capital letter',
    password_policy_errors('passw0rd') !== []);

test('rejects a password without a small letter',
    password_policy_errors('PASSW0RD') !== []);

test('rejects a password without a number',
    password_policy_errors('Password') !== []);

test('rejects a password containing a space',
    password_policy_errors('Pass 123') !== []);

test('accepts a compliant password',
    password_policy_errors('Passw0rd') === []);

test('password_is_valid mirrors password_policy_errors',
    password_is_valid('Passw0rd') === true && password_is_valid('passw0rd') === false);

test('the policy hint mentions the capital letter rule',
    stripos(password_policy_hint(), 'capital') !== false);

test('generated temporary passwords satisfy the policy',
    password_is_valid(generate_temp_password(10)));

test('generated temporary passwords respect the requested length',
    strlen(generate_temp_password(12)) === 12);

test('generated temporary passwords are not predictable',
    generate_temp_password(10) !== generate_temp_password(10));

// ---------------------------------------------------------------------------
// Output escaping and helpers
// ---------------------------------------------------------------------------
section('Output escaping and helpers');

test('e() escapes HTML',
    e('<script>alert(1)</script>') === '&lt;script&gt;alert(1)&lt;/script&gt;');

test('e() escapes quotes for attributes',
    e('a"b\'c') === 'a&quot;b&#039;c');

test('url() builds an absolute application path',
    url('auth/login.php') === APP_BASE . '/auth/login.php');

test('pass_fail_badge renders a pass badge',
    strpos(pass_fail_badge('pass'), 'PASS') !== false);

test('pass_fail_badge renders a fail badge',
    strpos(pass_fail_badge('fail'), 'FAIL') !== false);

test('pass_fail_badge handles a missing value',
    strpos(pass_fail_badge(null), 'N/A') !== false);

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------
section('CSRF protection');

$token = csrf_token();
test('csrf_token returns a non-empty token', is_string($token) && strlen($token) >= 32);
test('csrf_token is stable within a session', csrf_token() === $token);
test('verify_csrf accepts the issued token', verify_csrf($token) === true);
test('verify_csrf rejects a wrong token', verify_csrf('not-the-token') === false);
test('csrf_field renders a hidden input', strpos(csrf_field(), 'name="csrf_token"') !== false);

// ---------------------------------------------------------------------------
// Examination windows
// ---------------------------------------------------------------------------
section('Examination scheduling');

test('an exam without a window is open',
    exam_window_state(['available_from' => null, 'available_until' => null]) === 'open');

test('an exam that opens later is upcoming',
    exam_window_state(['available_from' => date('Y-m-d H:i:s', time() + 3600)]) === 'upcoming');

test('an exam that closed earlier is closed',
    exam_window_state(['available_until' => date('Y-m-d H:i:s', time() - 3600)]) === 'closed');

test('an exam inside its window is open',
    exam_window_state([
        'available_from'  => date('Y-m-d H:i:s', time() - 3600),
        'available_until' => date('Y-m-d H:i:s', time() + 3600),
    ]) === 'open');

// ---------------------------------------------------------------------------
// AI helpers
// ---------------------------------------------------------------------------
section('AI helpers');

test('identical questions have a similarity of 1',
    abs(ai_similarity('What is a database?', 'What is a database?') - 1.0) < 0.0001);

test('unrelated questions have a low similarity',
    ai_similarity('What is a database?', 'Name a primary colour') < 0.3);

$duplicates = ai_duplicate_pairs([
    ['id' => 1, 'question_text' => 'What is normalisation in databases?'],
    ['id' => 2, 'question_text' => 'What is normalisation in databases?'],
    ['id' => 3, 'question_text' => 'Explain the SOLID principles of object oriented design'],
], 0.62);

test('the duplicate detector finds the identical pair', count($duplicates) === 1);
test('the duplicate detector reports both ids',
    !empty($duplicates) && in_array(1, $duplicates[0], true) && in_array(2, $duplicates[0], true));

$generated = ai_generate_questions('Database Normalization', 3, 'mcq', 12345);
test('the generator returns the requested number of questions', count($generated) === 3);
test('every generated question has a text', $generated !== [] && $generated[0]['question_text'] !== '');
test('every generated question has four options',
    $generated !== []
    && $generated[0]['option_a'] !== '' && $generated[0]['option_b'] !== ''
    && $generated[0]['option_c'] !== '' && $generated[0]['option_d'] !== '');
test('every generated question has a valid correct option',
    $generated !== [] && in_array($generated[0]['correct_option'], ['A', 'B', 'C', 'D'], true));

// ---------------------------------------------------------------------------
// AI: no repeated questions (exact, paraphrase, near-duplicate)
// ---------------------------------------------------------------------------
section('AI: no repeated questions');

test('fingerprint ignores case and punctuation',
    ai_question_fingerprint('What is a database?') === ai_question_fingerprint('what is a database'));

test('paraphrased duplicate is detected',
    ai_is_duplicate(
        'A table Enrolment(StudentID, CourseID, CourseTitle) has primary key (StudentID, CourseID), and CourseTitle depends only on CourseID. Which normal-form rule does this partial dependency violate?',
        ['In Enrolment, CourseTitle depends only on CourseID although the key is StudentID plus CourseID. This partial dependency violates which normal form?'],
        0.55
    ) === true);

test('unrelated question is not flagged as duplicate',
    ai_is_duplicate('What is the capital of France?', ['What is the largest ocean on Earth?'], 0.55) === false);

$batch = ai_generate_questions('Database Normalization', 8, 'mcq', 111, [], 'difficult', 'university');
test('generated batch has the requested count', count($batch) === 8);
$fp = [];
$fpDup = false;
foreach ($batch as $q) {
    $f = ai_question_fingerprint($q['question_text']);
    if (isset($fp[$f])) { $fpDup = true; }
    $fp[$f] = true;
}
test('generated batch has no exact duplicates', !$fpDup);
$intraDup = false;
for ($i = 0; $i < count($batch); $i++) {
    for ($j = $i + 1; $j < count($batch); $j++) {
        if (ai_duplicate_score($batch[$i], $batch[$j]) >= 0.55) { $intraDup = true; }
    }
}
test('generated batch has no near-duplicates', !$intraDup);

$existing = array_map(function ($q) { return $q['question_text']; }, $batch);
$batch2 = ai_generate_questions('Database Normalization', 8, 'mcq', 222, $existing, 'difficult', 'university');
test('second batch avoids the first batch', count($batch2) === 8 && count(array_intersect($existing, array_map(function ($q) { return $q['question_text']; }, $batch2))) === 0);
$crossDup = false;
foreach ($batch2 as $q) {
    if (ai_is_duplicate($q, $existing, 0.55)) { $crossDup = true; }
}
test('second batch has no paraphrase of the first batch', !$crossDup);

$dedup = ai_deduplicate_questions(
    array_merge($batch, array_slice($batch, 0, 2)),
    [],
    0.55
);
test('deduplicate rejects intra-batch repeats', count($dedup['kept']) === count($batch) && count($dedup['rejected']) === 2);

// ---------------------------------------------------------------------------
// AI: professional quality validation
// ---------------------------------------------------------------------------
section('AI: professional quality validation');

test('validator rejects the meaningless repeated template', ai_validate_question(['question_text' => 'Which term is most closely associated with X?', 'option_a' => 'A', 'option_b' => 'B', 'option_c' => 'C', 'option_d' => 'D', 'correct_option' => 'A']) !== []);
test('validator rejects commentary instead of a question', ai_validate_question(['question_text' => 'Here is a good question about databases for you?', 'option_a' => 'A', 'option_b' => 'B', 'option_c' => 'C', 'option_d' => 'D', 'correct_option' => 'A']) !== []);
test('validator rejects "all of the above" options', ai_validate_question(['question_text' => 'Which statement is correct about normalisation in databases?', 'option_a' => 'A', 'option_b' => 'All of the above', 'option_c' => 'C', 'option_d' => 'D', 'correct_option' => 'A']) !== []);
test('validator rejects duplicate options', ai_validate_question(['question_text' => 'Which statement is correct about normalisation in databases?', 'option_a' => 'Same', 'option_b' => 'same', 'option_c' => 'C', 'option_d' => 'D', 'correct_option' => 'A']) !== []);
test('validator accepts a generated question', ai_validate_question($batch[0]) === []);

// ---------------------------------------------------------------------------
// AI: difficult, advanced, varied generation
// ---------------------------------------------------------------------------
section('AI: difficult and varied generation');

$adv = ai_generate_questions('Introduction to Computer Networks', 12, 'mcq', 999, [], 'difficult', 'university');
test('generator returns 12 advanced questions', count($adv) === 12);
$advTexts = array_map(function ($q) { return $q['question_text']; }, $adv);
test('all advanced question texts are distinct', count(array_unique($advTexts)) === 12);
$stems = [];
foreach ($advTexts as $t) {
    $stem = strtolower(preg_replace('/[^a-z]+/', ' ', substr($t, 0, 40)));
    $stems[$stem] = true;
}
test('question structures vary across the batch', count($stems) >= 4);
$easyRecall = ['How many bits are there in one byte?', 'What is the value of 7 x 8?', 'Which of the following is an input device?'];
test('no basic-recall questions are generated', count(array_intersect($easyRecall, $advTexts)) === 0);
$allValid = true;
foreach ($adv as $q) { if (ai_validate_question($q) !== []) { $allValid = false; } }
test('every generated question passes validation', $allValid);

$tf = ai_generate_questions('Database design', 10, 'true_false', 555);
test('true/false generator returns the requested count', count($tf) === 10);
$tfTexts = array_map(function ($q) { return $q['question_text']; }, $tf);
test('true/false batch has no duplicates', count(array_unique($tfTexts)) === 10);

$mixed = ai_generate_questions('Introduction to Computer Networks', 10, 'mixed', 777);
test('mixed generator returns the requested count', count($mixed) === 10);

$reg = ai_generate_exam(['topic' => 'Database Normalization', 'count' => 5, 'type' => 'mcq', 'seed' => 42, 'existing' => $existing]);
$regTexts = array_map(function ($q) { return $q['question_text']; }, $reg['questions']);
test('exam draft has no duplicates and avoids existing questions', count($reg['questions']) === 5 && count(array_intersect($existing, $regTexts)) === 0);

// ---------------------------------------------------------------------------
// Registration numbers + student status helpers
// ---------------------------------------------------------------------------
section('Registration numbers');

test('flexible format accepts UG24/ICTC/1099', is_valid_reg_number('UG24/ICTC/1099', 'flexible'));
test('flexible format accepts a date-style number 28/11/2006', is_valid_reg_number('28/11/2006', 'flexible'));
test('flexible format normalises spaces away', is_valid_reg_number('UG 24', 'flexible') && normalize_reg_number('UG 24') === 'UG24');
test('flexible format rejects empty', !is_valid_reg_number('', 'flexible'));
test('date format accepts 28/11/2006', is_valid_reg_number('28/11/2006', 'date'));
test('date format rejects 31/02/2006 (not a real date)', !is_valid_reg_number('31/02/2006', 'date'));
test('date format rejects alphanumeric numbers', !is_valid_reg_number('UG24/ICTC/1099', 'date'));
test('date format rejects wrong separator', !is_valid_reg_number('28-11-2006', 'date'));
test('normalise removes spaces', normalize_reg_number(' UG24 / ICTC ') === 'UG24/ICTC');

// ---------------------------------------------------------------------------
// Examination visibility: NOT YET OPEN / OPEN / CLOSED
// ---------------------------------------------------------------------------
section('Examination visibility');

test('inactive exam is NOT YET OPEN', exam_display_status(['status' => 'inactive', 'available_from' => null, 'available_until' => null]) === 'NOT YET OPEN');
test('active exam with no window is OPEN', exam_display_status(['status' => 'active', 'available_from' => null, 'available_until' => null]) === 'OPEN');
test('closed exam is CLOSED', exam_display_status(['status' => 'closed', 'available_from' => null, 'available_until' => null]) === 'CLOSED');
test('active exam past closing time is CLOSED', exam_display_status(['status' => 'active', 'available_from' => null, 'available_until' => date('Y-m-d H:i:s', time() - 3600)]) === 'CLOSED');
test('active exam before opening time is NOT YET OPEN', exam_display_status(['status' => 'active', 'available_from' => date('Y-m-d H:i:s', time() + 3600), 'available_until' => null]) === 'NOT YET OPEN');
test('active exam inside window is OPEN', exam_display_status(['status' => 'active', 'available_from' => date('Y-m-d H:i:s', time() - 3600), 'available_until' => date('Y-m-d H:i:s', time() + 3600)]) === 'OPEN');

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n" . str_repeat('-', 60) . "\n";
echo "Passed: {$passed}   Failed: {$failed}\n";

if ($failed > 0) {
    echo "\nFailed tests:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

echo "All tests passed.\n";
exit(0);
