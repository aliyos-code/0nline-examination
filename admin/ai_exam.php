<?php
/**
 * Administrator (only): AI Examination Generator.
 *
 * Describe a topic and the built-in offline AI engine drafts a complete
 * examination - title, instructions, settings and questions. The administrator
 * reviews and edits the draft, then saves it (as an inactive examination) and
 * can add or refine it further from the Questions screen.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/openai.php';

$pdo    = db();
$errors = [];
$view   = 'form';
$draft  = null;

$types = [
    'mcq'        => 'Multiple choice (A-D)',
    'true_false' => 'True / False',
    'mixed'      => 'Mixed (both)',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        set_flash('danger', 'Your session expired. Please try again.');
        redirect('admin/ai_exam.php');
    }

    $action = input('action');

    // -----------------------------------------------------------------------
    // Generate / regenerate a draft
    // -----------------------------------------------------------------------
    if ($action === 'generate' || $action === 'regenerate') {
        $topic      = input('topic');
        $count      = (int) input('count');
        $type       = input('type', 'mcq');
        $courseName = input('course_name');
        $courseCode = input('course_code');
        $duration   = (int) input('duration_minutes');
        $passMark   = (int) input('pass_mark');
        $difficulty = input('difficulty', 'difficult');
        $level      = input('academic_level', 'university');

        if ($topic === '')      { $errors[] = 'Please describe the topic or subject.'; }
        if ($count < 1 || $count > 100) { $errors[] = 'Number of questions must be between 1 and 100.'; }
        if (!isset($types[$type])) { $type = 'mcq'; }
        if ($duration < 1 || $duration > 600) { $duration = max(10, $count * 2); }
        if ($passMark < 0 || $passMark > 100) { $passMark = 50; }

        if (!$errors) {
            $seed  = random_int(1, 1000000000);
            // Avoid repeating any question already in the database.
            $existing = ai_load_existing_question_texts($pdo, null);
            $hybrid = openai_generate_questions_with_fallback(
                $topic, $count, $type, $courseName, $courseCode,
                $existing, ai_sanitise_difficulty($difficulty), ai_sanitise_level($level), $seed
            );
            $draft = ai_generate_exam([
                'topic'       => $topic,
                'count'       => $count,
                'type'        => $type,
                'course_name' => $courseName,
                'course_code' => $courseCode,
                'duration'    => $duration,
                'pass_mark'   => $passMark,
                'seed'        => $seed,
                'existing'    => $existing,
                'difficulty'  => ai_sanitise_difficulty($difficulty),
                'academic_level' => ai_sanitise_level($level),
                'pregenerated_questions' => $hybrid['questions'],
            ]);
            $_SESSION['ai_draft'] = $draft;
            $view = 'preview';
        }
    }

    // -----------------------------------------------------------------------
    // Save the reviewed draft as a real examination
    // -----------------------------------------------------------------------
    if ($action === 'save') {
        $title      = input('title');
        $courseName = input('course_name');
        $courseCode = input('course_code');
        $instructions = isset($_POST['instructions']) ? trim((string) $_POST['instructions']) : '';
        $duration   = (int) input('duration_minutes');
        $passMark   = (int) input('pass_mark');

        if ($title === '')      { $errors[] = 'Examination title is required.'; }
        if ($courseName === '') { $errors[] = 'Course name is required.'; }
        if ($courseCode === '') { $errors[] = 'Course code is required.'; }
        if ($duration < 1 || $duration > 600) { $errors[] = 'Duration must be between 1 and 600 minutes.'; }
        if ($passMark < 0 || $passMark > 100) { $errors[] = 'Pass mark must be between 0 and 100.'; }

        $rows    = isset($_POST['q']) && is_array($_POST['q']) ? $_POST['q'] : [];
        $skips   = isset($_POST['skip']) && is_array($_POST['skip']) ? $_POST['skip'] : [];
        $valid   = [];
        $invalid = 0;

        foreach ($rows as $i => $row) {
            if (isset($skips[$i])) {
                continue;
            }
            $text = isset($row['text']) ? trim((string) $row['text']) : '';
            $a    = isset($row['a']) ? trim((string) $row['a']) : '';
            $b    = isset($row['b']) ? trim((string) $row['b']) : '';
            $c    = isset($row['c']) ? trim((string) $row['c']) : '';
            $d    = isset($row['d']) ? trim((string) $row['d']) : '';
            $correct = strtoupper(isset($row['correct']) ? trim((string) $row['correct']) : '');

            if ($text === '' || $a === '' || $b === '') {
                $invalid++;
                continue;
            }
            $present = ['A' => $a, 'B' => $b];
            if ($c !== '') { $present['C'] = $c; }
            if ($d !== '') { $present['D'] = $d; }
            if (!isset($present[$correct])) {
                $invalid++;
                continue;
            }
            $valid[] = [
                'question_text'  => $text,
                'option_a'       => $a,
                'option_b'       => $b,
                'option_c'       => $c,
                'option_d'       => $d,
                'correct_option' => $correct,
            ];
        }

        // Reject duplicates / invalid rows against the database and
        // within the draft itself before saving.
        $duplicateSkipped = 0;
        if (!$errors && $valid) {
            $existingAll = ai_load_existing_question_texts($pdo, null);
            $filtered = ai_deduplicate_questions($valid, $existingAll, 0.55);
            $invalid += count($filtered['rejected']);
            $duplicateSkipped = count($filtered['rejected']);
            $valid = $filtered['kept'];
        }

        if (!$errors && !$valid) {
            $errors[] = 'The examination must contain at least one complete, non-duplicate question.';
        }

        if (!$errors) {
            $pdo->beginTransaction();
            try {
                $insert = $pdo->prepare(
                    'INSERT INTO examinations
                        (title, course_name, course_code, instructions,
                         duration_minutes, total_questions, pass_mark, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, \'inactive\')'
                );
                $insert->execute([$title, $courseName, $courseCode, $instructions,
                                  $duration, count($valid), $passMark]);
                $examId = (int) $pdo->lastInsertId();

                $insertQ = $pdo->prepare(
                    'INSERT INTO questions
                        (examination_id, question_text, option_a, option_b, option_c, option_d, correct_option)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                foreach ($valid as $q) {
                    $insertQ->execute([$examId, $q['question_text'], $q['option_a'], $q['option_b'],
                                       $q['option_c'], $q['option_d'], $q['correct_option']]);
                }

                $pdo->commit();
            } catch (Exception $ex) {
                $pdo->rollBack();
                set_flash('danger', 'Could not save the generated examination. Please try again.');
                redirect('admin/ai_exam.php');
            }

            unset($_SESSION['ai_draft']);
            audit_log('exam.ai_create', 'examination', $examId, $title . ' (' . count($valid) . ' questions, ' . $duplicateSkipped . ' duplicates rejected)');
            $msg = 'AI examination created with ' . count($valid) . ' question(s).';
            if ($duplicateSkipped > 0) {
                $msg .= ' ' . $duplicateSkipped . ' duplicate/invalid row(s) were rejected.';
            } elseif ($invalid > 0) {
                $msg .= ' ' . $invalid . ' incomplete row(s) were skipped.';
            }
            set_flash('success', $msg);
            redirect('admin/questions.php?exam=' . $examId);
        } else {
            // Keep the reviewed draft on screen so the administrator can fix it.
            $draft = isset($_SESSION['ai_draft']) ? $_SESSION['ai_draft'] : null;
            $view  = $draft ? 'preview' : 'form';
        }
    }

    if ($action === 'discard') {
        unset($_SESSION['ai_draft']);
        set_flash('info', 'Generated draft discarded.');
        redirect('admin/ai_exam.php');
    }
}

// Defaults for the specification form.
$spec = [
    'topic'            => input('topic'),
    'course_name'      => input('course_name'),
    'course_code'      => input('course_code'),
    'count'            => input('count') !== '' ? (int) input('count') : 10,
    'type'             => isset($types[input('type')]) ? input('type') : 'mcq',
    'duration_minutes' => input('duration_minutes') !== '' ? (int) input('duration_minutes') : 20,
    'pass_mark'        => input('pass_mark') !== '' ? (int) input('pass_mark') : 50,
    'difficulty'       => ai_sanitise_difficulty(input('difficulty', 'difficult')),
    'academic_level'   => ai_sanitise_level(input('academic_level', 'university')),
];

$page_title = 'AI Exam Generator';
$active = 'ai';
require __DIR__ . '/../includes/header.php';
?>
<div class="row">
    <div class="col-lg-3 mb-4">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    </div>

    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h1 class="h3 mb-0"><i class="bi bi-robot me-1"></i>AI Exam Generator</h1>
            <a href="<?php echo url('admin/examinations.php'); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to examinations
            </a>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($view === 'form'): ?>
            <!-- =============================== GENERATOR FORM =============================== -->
            <div class="alert alert-info d-flex gap-2">
                <i class="bi bi-info-circle-fill"></i>
                <div>
                    Describe a topic and the AI drafts a difficult, university-level examination.
                    Existing questions are never repeated. Works offline; uses OpenAI when configured.
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold">Describe the examination</div>
                <div class="card-body">
                    <form method="post" action="<?php echo url('admin/ai_exam.php'); ?>" novalidate>
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="generate">

                        <div class="mb-3">
                            <label class="form-label">Topic / subject <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="topic" required
                                   placeholder="e.g. Introduction to Computer Networks"
                                   value="<?php echo e($spec['topic']); ?>">
                            <div class="form-text">Be specific - the AI tailors the questions to these keywords.</div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Course name</label>
                                <input type="text" class="form-control" name="course_name"
                                       placeholder="(optional)" value="<?php echo e($spec['course_name']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Course code</label>
                                <input type="text" class="form-control" name="course_code"
                                       placeholder="(optional)" value="<?php echo e($spec['course_code']); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Questions</label>
                                <input type="number" class="form-control" name="count" min="1" max="100"
                                       value="<?php echo e($spec['count']); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Question type</label>
                                <select class="form-select" name="type">
                                    <?php foreach ($types as $val => $label): ?>
                                        <option value="<?php echo e($val); ?>" <?php echo $spec['type'] === $val ? 'selected' : ''; ?>>
                                            <?php echo e($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Duration (min)</label>
                                <input type="number" class="form-control" name="duration_minutes" min="1" max="600"
                                       value="<?php echo e($spec['duration_minutes']); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Pass mark (%)</label>
                                <input type="number" class="form-control" name="pass_mark" min="0" max="100"
                                       value="<?php echo e($spec['pass_mark']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Difficulty</label>
                                <select class="form-select" name="difficulty">
                                    <?php foreach (ai_difficulty_options() as $val => $label): ?>
                                        <option value="<?php echo e($val); ?>" <?php echo $spec['difficulty'] === $val ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Academic level</label>
                                <select class="form-select" name="academic_level">
                                    <?php foreach (ai_level_options() as $val => $label): ?>
                                        <option value="<?php echo e($val); ?>" <?php echo $spec['academic_level'] === $val ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-magic me-1"></i>Generate draft
                            </button>
                        </div>
                    </form>
                </div>
            </div>

        <?php elseif ($draft): ?>
            <!-- =============================== REVIEW PREVIEW =============================== -->
            <div class="alert alert-success d-flex gap-2">
                <i class="bi bi-stars"></i>
                <div>
                    The AI drafted <strong><?php echo count($draft['questions']); ?></strong> question(s).
                    Review, edit or untick anything you do not want, then save.
                </div>
            </div>

            <form method="post" action="<?php echo url('admin/ai_exam.php'); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="topic" value="<?php echo e($draft['topic']); ?>">
                <input type="hidden" name="type" value="<?php echo e($draft['type']); ?>">
                <input type="hidden" name="count" value="<?php echo count($draft['questions']); ?>">
                <input type="hidden" name="difficulty" value="<?php echo e(isset($draft['difficulty']) ? $draft['difficulty'] : 'difficult'); ?>">
                <input type="hidden" name="academic_level" value="<?php echo e(isset($draft['academic_level']) ? $draft['academic_level'] : 'university'); ?>">
                <input type="hidden" name="course_name" value="<?php echo e($draft['course_name']); ?>">
                <input type="hidden" name="course_code" value="<?php echo e($draft['course_code']); ?>">

                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white fw-semibold">Examination details</div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Title</label>
                                <input type="text" class="form-control" name="title" required
                                       value="<?php echo e($draft['title']); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Course code</label>
                                <input type="text" class="form-control" name="course_code" required
                                       value="<?php echo e($draft['course_code']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Course name</label>
                                <input type="text" class="form-control" name="course_name" required
                                       value="<?php echo e($draft['course_name']); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Duration (min)</label>
                                <input type="number" class="form-control" name="duration_minutes" min="1" max="600"
                                       value="<?php echo (int) $draft['duration_minutes']; ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Pass mark (%)</label>
                                <input type="number" class="form-control" name="pass_mark" min="0" max="100"
                                       value="<?php echo (int) $draft['pass_mark']; ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Instructions</label>
                                <textarea class="form-control" name="instructions" rows="3"><?php echo e($draft['instructions']); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <?php foreach ($draft['questions'] as $i => $q): ?>
                    <div class="card shadow-sm mb-3">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <span class="fw-semibold">Question <?php echo $i + 1; ?></span>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="skip[<?php echo $i; ?>]"
                                       id="skip<?php echo $i; ?>" value="1">
                                <label class="form-check-label small" for="skip<?php echo $i; ?>">Skip this one</label>
                            </div>
                        </div>
                        <div class="card-body">
                            <textarea class="form-control mb-3" name="q[<?php echo $i; ?>][text]" rows="2"><?php echo e($q['question_text']); ?></textarea>
                            <div class="row g-2">
                                <?php
                                foreach (['a', 'b', 'c', 'd'] as $col):
                                    $val = isset($q['option_' . $col]) ? $q['option_' . $col] : '';
                                ?>
                                    <div class="col-md-6">
                                        <div class="input-group">
                                            <span class="input-group-text"><?php echo strtoupper($col); ?></span>
                                            <input type="text" class="form-control"
                                                   name="q[<?php echo $i; ?>][<?php echo $col; ?>]"
                                                   value="<?php echo e($val); ?>">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <div class="col-md-4">
                                    <label class="form-label small mb-1">Correct answer</label>
                                    <select class="form-select" name="q[<?php echo $i; ?>][correct]">
                                        <?php foreach (['A', 'B', 'C', 'D'] as $letter): ?>
                                            <option value="<?php echo $letter; ?>" <?php echo $q['correct_option'] === $letter ? 'selected' : ''; ?>>
                                                <?php echo $letter; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <button type="submit" class="btn btn-success" name="action" value="save">
                        <i class="bi bi-save me-1"></i>Save examination
                    </button>
                    <button type="submit" class="btn btn-outline-primary" name="action" value="regenerate" formnovalidate>
                        <i class="bi bi-arrow-repeat me-1"></i>Regenerate
                    </button>
                    <button type="submit" class="btn btn-outline-secondary" name="action" value="discard" formnovalidate>
                        <i class="bi bi-x-circle me-1"></i>Discard
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
