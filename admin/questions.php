<?php
/**
 * Administrator: manage the multiple-choice questions of an examination.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$pdo    = db();
$errors = [];

require_once __DIR__ . '/../includes/csv.php';
require_once __DIR__ . '/../includes/ai.php';
require_once __DIR__ . '/../includes/openai.php';

// Bulk-import template (must run before any HTML output).
if (input('template') === 'questions') {
    csv_send('questions_template.csv', [
        ['question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_option'],
        ['Which of the following is an input device?', 'Monitor', 'Keyboard', 'Speaker', 'Projector', 'B'],
        ['How many bits are there in one byte?', '4', '8', '16', '32', 'B'],
    ]);
}

// Export questions of the selected examination as CSV.
if (input('export') === 'questions' && (int) input('exam') > 0) {
    $eid = (int) input('exam');
    $stmt = $pdo->prepare('SELECT question_text, option_a, option_b, option_c, option_d, correct_option, difficulty, category FROM questions WHERE examination_id = ? ORDER BY id ASC');
    $stmt->execute([$eid]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="questions_exam_' . $eid . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_option', 'difficulty', 'category']);
    foreach ($stmt->fetchAll() as $r) {
        fputcsv($out, [$r['question_text'], $r['option_a'], $r['option_b'], $r['option_c'], $r['option_d'], $r['correct_option'], $r['difficulty'], $r['category']]);
    }
    fclose($out);
    exit;
}

$examinations = $pdo->query('SELECT id, title, course_code FROM examinations ORDER BY title ASC')->fetchAll();

// Determine the selected examination.
$selectedExamId = (int) input('exam');
if ($selectedExamId <= 0 && $examinations) {
    $selectedExamId = (int) $examinations[0]['id'];
}

$selectedExam = null;
if ($selectedExamId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM examinations WHERE id = ?');
    $stmt->execute([$selectedExamId]);
    $selectedExam = $stmt->fetch();
    if (!$selectedExam) {
        $selectedExamId = 0;
    }
}

// ---------------------------------------------------------------------------
// Actions (POST)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        set_flash('danger', 'Your session expired. Please try again.');
        redirect('admin/questions.php?exam=' . $selectedExamId);
    }

    $action = input('action');

    if ($action === 'delete') {
        $id = (int) input('id');
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM questions WHERE id = ?');
            $stmt->execute([$id]);
            audit_log('question.delete', 'question', $id);
            set_flash('success', 'Question deleted.');
        }
        redirect('admin/questions.php?exam=' . $selectedExamId);
    }

    if ($action === 'save') {
        $id           = (int) input('id');
        $examId       = (int) input('examination_id');
        $questionText = input('question_text');
        $optionA      = input('option_a');
        $optionB      = input('option_b');
        $optionC      = input('option_c');
        $optionD      = input('option_d');
        $correct      = input('correct_option');
        $difficulty   = input('difficulty', 'medium');
        if (!in_array($difficulty, ['easy', 'medium', 'difficult'], true)) { $difficulty = 'medium'; }
        $category     = input('category');
        if (strlen($category) > 100) { $category = substr($category, 0, 100); }
        $type         = input('question_type', 'mcq');
        if ($type !== 'true_false') {
            $type = 'mcq';
        }

        if ($examId <= 0)            { $errors[] = 'Please select an examination.'; }
        if ($questionText === '')    { $errors[] = 'Question text is required.'; }
        if ($optionA === '')         { $errors[] = 'Option A is required.'; }
        if ($optionB === '')         { $errors[] = 'Option B is required.'; }

        if ($type === 'true_false') {
            // Keep only the two fixed options and the matching answer.
            $optionC = '';
            $optionD = '';
            if (!in_array($correct, ['A', 'B'], true)) { $errors[] = 'Please choose True or False.'; }
        } else {
            if ($optionC === '')     { $errors[] = 'Option C is required.'; }
            if ($optionD === '')     { $errors[] = 'Option D is required.'; }
            if (!in_array($correct, ['A', 'B', 'C', 'D'], true)) { $errors[] = 'Please choose the correct answer.'; }
        }

        // Database validation: never save an exact, paraphrase or
        // near-duplicate of another question in the same examination.
        if (!$errors && $examId > 0 && $questionText !== '') {
            $candidate = [
                'question_text' => $questionText,
                'option_a' => $optionA, 'option_b' => $optionB,
                'option_c' => $optionC, 'option_d' => $optionD,
                'correct_option' => $correct,
            ];
            $existing = ai_load_exam_questions($pdo, $examId);
            if ($id > 0) {
                $existing = array_values(array_filter($existing, function ($r) use ($id) { return (int) $r['id'] !== $id; }));
            }
            $dup = ai_find_duplicate($candidate, $existing, 0.55);
            if ($dup !== null) {
                $errors[] = 'A similar question already exists in this examination. Duplicates are not saved.';
            }
        }

        if (!$errors) {
            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE questions
                     SET question_text = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_option = ?, difficulty = ?, category = ?
                     WHERE id = ?'
                );
                $stmt->execute([$questionText, $optionA, $optionB, $optionC, $optionD, $correct, $difficulty, $category !== '' ? $category : null, $id]);
                audit_log('question.update', 'question', $id);
                set_flash('success', 'Question updated.');
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO questions
                        (examination_id, question_text, option_a, option_b, option_c, option_d, correct_option, difficulty, category)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$examId, $questionText, $optionA, $optionB, $optionC, $optionD, $correct, $difficulty, $category !== '' ? $category : null]);
                audit_log('question.create', 'question', (int) $pdo->lastInsertId(), 'Exam #' . $examId);
                set_flash('success', 'Question added.');
            }
            redirect('admin/questions.php?exam=' . $examId);
        }
    }

    if ($action === 'ai_generate') {
        $examId = (int) input('examination_id');
        if ($examId <= 0) {
            set_flash('danger', 'Please select an examination first.');
            redirect('admin/questions.php');
        }
        $topic = input('ai_topic');
        $count = (int) input('ai_count');
        $type  = input('ai_type', 'mcq');
        $difficulty = input('ai_difficulty', 'difficult');
        $level = input('ai_level', 'university');
        if ($topic === '') {
            set_flash('warning', 'Describe a topic for the AI to generate questions about.');
            redirect('admin/questions.php?exam=' . $examId);
        }
        if ($count < 1 || $count > 100) {
            $count = 5;
        }
        // Database validation: load existing questions for this exam.
        $stmt = $pdo->prepare('SELECT * FROM examinations WHERE id = ?');
        $stmt->execute([$examId]);
        $examRow = $stmt->fetch();
        $existing = ai_load_existing_question_texts($pdo, $examId);
        $result = openai_generate_questions_with_fallback(
            $topic,
            $count,
            $type,
            $examRow ? $examRow['course_name'] : '',
            $examRow ? $examRow['course_code'] : '',
            $existing,
            ai_sanitise_difficulty($difficulty),
            ai_sanitise_level($level),
            random_int(1, 1000000000)
        );
        $generated = $result['questions'];
        $filtered = ai_deduplicate_questions($generated, $existing, 0.55);
        $approved = $filtered['kept'];
        $rejected = count($filtered['rejected']) + (isset($result['rejected']) ? (int) $result['rejected'] : 0);

        // Save approved questions only, inside a transaction. Re-check
        // each insert against the database to guarantee no duplicates.
        $pdo->beginTransaction();
        try {
            $check = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE examination_id = ? AND question_text = ?');
            $insert = $pdo->prepare(
                'INSERT INTO questions
                    (examination_id, question_text, option_a, option_b, option_c, option_d, correct_option, difficulty, category)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $aiDiff = ai_sanitise_difficulty($difficulty) === 'easy' ? 'easy' : (ai_sanitise_difficulty($difficulty) === 'moderate' ? 'medium' : 'difficult');
            $added = 0;
            foreach ($approved as $g) {
                $check->execute([$examId, $g['question_text']]);
                if ((int) $check->fetchColumn() > 0) {
                    $rejected++;
                    continue;
                }
                $insert->execute([$examId, $g['question_text'], $g['option_a'], $g['option_b'],
                                  $g['option_c'], $g['option_d'], $g['correct_option'], $aiDiff, substr((string) $topic, 0, 100)]);
                $added++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            set_flash('danger', 'Could not save the generated questions. Please try again.');
            redirect('admin/questions.php?exam=' . $examId);
        }
        audit_log('question.ai_generate', 'examination', $examId, $added . ' added, ' . $rejected . ' rejected from "' . $topic . '" (' . $result['source'] . ')');
        if ($added === 0) {
            set_flash('warning', 'No new unique questions could be generated for this topic. All candidates were duplicates or invalid.');
        } else {
            $msg = $added . ' question(s) added.';
            if ($rejected > 0) { $msg .= ' ' . $rejected . ' duplicate/invalid candidate(s) rejected.'; }
            if ($added < $count) { $msg .= ' Only ' . $added . ' unique questions were available offline for this topic.'; }
            set_flash('success', $msg);
        }
        redirect('admin/questions.php?exam=' . $examId);
    }

    if ($action === 'import') {
        $examId = (int) input('examination_id');
        if ($examId <= 0) {
            set_flash('danger', 'Please select an examination before importing.');
            redirect('admin/questions.php');
        }

        $readError = null;
        $rows = csv_read_upload('csv_file', $readError);
        if ($rows === null) {
            set_flash('danger', 'Import failed: ' . $readError);
            redirect('admin/questions.php?exam=' . $examId);
        }

        $insert   = $pdo->prepare(
            'INSERT INTO questions
                (examination_id, question_text, option_a, option_b, option_c, option_d, correct_option)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $imported = 0;
        $problems = [];
        $rowNo    = 0;

        foreach ($rows as $row) {
            $rowNo++;
            if (count($row) < 6) {
                $problems[] = 'Data row ' . $rowNo . ': expected 6 columns.';
                continue;
            }
            list($qText, $a, $b, $c, $d, $correct) = $row;
            $correct = strtoupper($correct);

            if ($qText === '' || $a === '' || $b === '' || $c === '' || $d === '') {
                $problems[] = 'Data row ' . $rowNo . ': missing question text or an option.';
                continue;
            }
            if (!in_array($correct, ['A', 'B', 'C', 'D'], true)) {
                $problems[] = 'Data row ' . $rowNo . ': correct_option must be A, B, C or D.';
                continue;
            }

            $insert->execute([$examId, $qText, $a, $b, $c, $d, $correct]);
            $imported++;
        }

        if ($imported > 0) {
            audit_log('question.import', 'examination', $examId, $imported . ' imported');
        }

        if ($problems) {
            set_flash('warning', $imported . ' question(s) imported, ' . count($problems) . ' row(s) skipped.');
            set_flash('info', implode('  ', array_slice($problems, 0, 10))
                . (count($problems) > 10 ? '  (+' . (count($problems) - 10) . ' more)' : ''));
        } else {
            set_flash('success', $imported . ' question(s) imported successfully.');
        }
        redirect('admin/questions.php?exam=' . $examId);
    }

    if ($action === 'copy_questions') {
        $sourceExamId = (int) input('source_exam_id');
        $targetExamId = (int) input('target_exam_id');
        $questionIds  = isset($_POST['question_ids']) ? array_map('intval', (array) $_POST['question_ids']) : [];
        $skipExisting = input('skip_existing') === '1';
        $updateTotal = input('update_total') === '1';

        if ($sourceExamId <= 0) { $errors[] = 'Choose the examination to copy from.'; }
        if ($targetExamId <= 0) { $errors[] = 'Choose the examination to copy into.'; }
        if ($sourceExamId > 0 && $sourceExamId === $targetExamId) {
            $errors[] = 'The source and the target examination must be different.';
        }

        if (!$errors) {
            $stmt = $pdo->prepare('SELECT id, title FROM examinations WHERE id IN (?, ?)');
            $stmt->execute([$sourceExamId, $targetExamId]);
            $found = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!isset($found[$sourceExamId]) || !isset($found[$targetExamId])) {
                $errors[] = 'The selected examinations could not be found.';
            }
        }

        if (!$errors) {
            $sql = 'SELECT * FROM questions WHERE examination_id = ?';
            $params = [$sourceExamId];
            if ($questionIds) {
                $sql .= ' AND id IN (' . implode(',', array_fill(0, count($questionIds), '?')) . ')';
                $params = array_merge($params, $questionIds);
            }
            $sql .= ' ORDER BY id ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            if (!$rows) {
                set_flash('warning', 'There are no questions to copy from that examination.');
                redirect('admin/questions.php?exam=' . $targetExamId);
            }

            $insert = $pdo->prepare(
                'INSERT INTO questions
                    (examination_id, question_text, option_a, option_b, option_c, option_d, correct_option)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $copied = 0;
            $skipped = 0;
            foreach ($rows as $row) {
                if ($skipExisting) {
                    $check = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE examination_id = ? AND question_text = ?');
                    $check->execute([$targetExamId, $row['question_text']]);
                    if ((int) $check->fetchColumn() > 0) {
                        $skipped++;
                        continue;
                    }
                }
                $insert->execute([$targetExamId, $row['question_text'], $row['option_a'], $row['option_b'],
                                  $row['option_c'], $row['option_d'], $row['correct_option']]);
                $copied++;
            }

            if ($updateTotal && $copied > 0) {
                $count = (int) $pdo->query('SELECT COUNT(*) FROM questions WHERE examination_id = ' . (int) $targetExamId)->fetchColumn();
                $pdo->prepare('UPDATE examinations SET total_questions = ? WHERE id = ?')->execute([$count, $targetExamId]);
            }

            audit_log('question.copy', 'examination', $targetExamId,
                      $copied . ' copied from examination #' . $sourceExamId);
            set_flash('success', $copied . ' question(s) copied.'
                . ($skipped ? ' ' . $skipped . ' skipped because they already exist in the target.' : ''));
            redirect('admin/questions.php?exam=' . $targetExamId);
        }
    }

    if ($action === 'ai_duplicates') {
        $examId = (int) input('examination_id');
        if ($examId > 0) {
            $stmt = $pdo->prepare('SELECT id, question_text FROM questions WHERE examination_id = ? ORDER BY id ASC');
            $stmt->execute([$examId]);
            $rows = $stmt->fetchAll();
            $pairs = ai_duplicate_pairs($rows, 0.55);

            if (!$pairs) {
                set_flash('success', 'AI check: no duplicate or near-duplicate questions were found.');
            } else {
                $lines = [];
                foreach (array_slice($pairs, 0, 8) as $p) {
                    $lines[] = '#' . $p['a_id'] . ' & #' . $p['b_id'] . ' (' . $p['score'] . '% similar)';
                }
                set_flash('warning', 'AI check found ' . count($pairs) . ' possible duplicate pair(s): '
                    . implode('; ', $lines) . (count($pairs) > 8 ? ' ...' : ''));
            }
        }
        redirect('admin/questions.php?exam=' . $examId);
    }
}

// Editing state
$editing = null;
$editId  = (int) input('edit');
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM questions WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch();
}

$qSearch = input('q_search');
$qDifficulty = input('q_difficulty');
$qCategory = input('q_category');
$questions = [];
if ($selectedExamId > 0) {
    $where = ['examination_id = ?'];
    $params = [$selectedExamId];
    if ($qSearch !== '') { $where[] = 'question_text LIKE ?'; $params[] = '%' . $qSearch . '%'; }
    if (in_array($qDifficulty, ['easy', 'medium', 'difficult'], true)) { $where[] = 'difficulty = ?'; $params[] = $qDifficulty; }
    if ($qCategory !== '') { $where[] = 'category LIKE ?'; $params[] = '%' . $qCategory . '%'; }
    $stmt = $pdo->prepare('SELECT * FROM questions WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC');
    $stmt->execute($params);
    $questions = $stmt->fetchAll();
}

// Source examination used by the "copy questions" panel.
$copyFromExamId = (int) input('copy_from');
$copyFromExam   = null;
$copyFromRows   = [];
if ($copyFromExamId > 0 && $copyFromExamId !== $selectedExamId) {
    $stmt = $pdo->prepare('SELECT * FROM examinations WHERE id = ?');
    $stmt->execute([$copyFromExamId]);
    $copyFromExam = $stmt->fetch();
    if ($copyFromExam) {
        $stmt = $pdo->prepare('SELECT * FROM questions WHERE examination_id = ? ORDER BY id ASC');
        $stmt->execute([$copyFromExamId]);
        $copyFromRows = $stmt->fetchAll();
    }
}

$page_title = 'Manage Questions';
$active = 'examinations';
require __DIR__ . '/../includes/header.php';

$form = [
    'id'            => $editing ? $editing['id'] : '',
    'question_text' => $editing ? $editing['question_text'] : '',
    'option_a'      => $editing ? $editing['option_a'] : '',
    'option_b'      => $editing ? $editing['option_b'] : '',
    'option_c'      => $editing ? $editing['option_c'] : '',
    'option_d'      => $editing ? $editing['option_d'] : '',
    'correct_option'=> $editing ? $editing['correct_option'] : 'A',
    'difficulty'    => $editing && isset($editing['difficulty']) ? $editing['difficulty'] : 'medium',
    'category'      => $editing && isset($editing['category']) ? $editing['category'] : '',
];
$formType = ($editing && $editing['option_c'] === '' && $editing['option_d'] === '') ? 'true_false' : 'mcq';
?>
<div class="row">
    <div class="col-lg-3 mb-4">
        <?php require __DIR__ . '/../includes/admin_sidebar.php'; ?>
    </div>

    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h1 class="h3 mb-0">Questions</h1>
            <a href="<?php echo url('admin/examinations.php'); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to examinations
            </a>
        </div>

        <?php if (!$examinations): ?>
            <div class="alert alert-info">Create an examination first before adding questions.
                <a href="<?php echo url('admin/examinations.php'); ?>" class="alert-link">Go to Examinations</a>.</div>
        <?php else: ?>

        <form class="row g-2 mb-4" method="get" action="<?php echo url('admin/questions.php'); ?>">
            <div class="col-sm-9">
                <select class="form-select" name="exam" onchange="this.form.submit()">
                    <?php foreach ($examinations as $exam): ?>
                        <option value="<?php echo (int) $exam['id']; ?>" <?php echo (int) $exam['id'] === $selectedExamId ? 'selected' : ''; ?>>
                            <?php echo e($exam['course_code'] . ' - ' . $exam['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3">
                <button class="btn btn-primary w-100" type="submit">Select</button>
            </div>
        </form>

        <?php if ($selectedExam): ?>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <p class="text-muted mb-0">
                    Showing questions for <strong><?php echo e($selectedExam['title']); ?></strong>
                    (<?php echo count($questions); ?> added, <?php echo (int) $selectedExam['total_questions']; ?> expected).
                </p>
                <a class="btn btn-outline-success btn-sm" href="<?php echo url('admin/questions.php?export=questions&exam=' . (int) $selectedExamId); ?>"><i class="bi bi-download me-1"></i>Export CSV</a>
            </div>
            <form class="row g-2 mb-3" method="get" action="<?php echo url('admin/questions.php'); ?>">
                <input type="hidden" name="exam" value="<?php echo (int) $selectedExamId; ?>">
                <div class="col-md-5"><input type="text" class="form-control" name="q_search" value="<?php echo e($qSearch); ?>" placeholder="Search questions"></div>
                <div class="col-md-2"><select class="form-select" name="q_difficulty">
                    <option value="">All levels</option>
                    <option value="easy" <?php echo $qDifficulty === 'easy' ? 'selected' : ''; ?>>Easy</option>
                    <option value="medium" <?php echo $qDifficulty === 'medium' ? 'selected' : ''; ?>>Medium</option>
                    <option value="difficult" <?php echo $qDifficulty === 'difficult' ? 'selected' : ''; ?>>Difficult</option>
                </select></div>
                <div class="col-md-3"><input type="text" class="form-control" name="q_category" value="<?php echo e($qCategory); ?>" placeholder="Category / subject"></div>
                <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-search me-1"></i>Filter</button></div>
            </form>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                <?php echo $editing ? 'Edit Question' : 'Add New Question'; ?>
            </div>
            <div class="card-body">
                <form method="post" action="<?php echo url('admin/questions.php?exam=' . $selectedExamId); ?>" novalidate>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo e($form['id']); ?>">
                    <input type="hidden" name="examination_id" value="<?php echo (int) $selectedExamId; ?>">

                    <div class="mb-3">
                        <label class="form-label">Question text</label>
                        <textarea class="form-control" name="question_text" rows="2" required><?php echo e($form['question_text']); ?></textarea>
                    </div>

                    <div class="col-md-4 mb-3">
                        <label class="form-label">Question type</label>
                        <select class="form-select" name="question_type" id="questionType">
                            <option value="mcq" <?php echo $formType === 'mcq' ? 'selected' : ''; ?>>Multiple choice (A&ndash;D)</option>
                            <option value="true_false" <?php echo $formType === 'true_false' ? 'selected' : ''; ?>>True / False</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Difficulty</label>
                        <select class="form-select" name="difficulty">
                            <option value="easy" <?php echo $form['difficulty'] === 'easy' ? 'selected' : ''; ?>>Easy</option>
                            <option value="medium" <?php echo $form['difficulty'] === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="difficult" <?php echo $form['difficulty'] === 'difficult' ? 'selected' : ''; ?>>Difficult</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Category / subject</label>
                        <input type="text" class="form-control" name="category" maxlength="100" value="<?php echo e($form['category']); ?>" placeholder="e.g. Database Normalization">
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" id="labelA">Option A</label>
                            <input type="text" class="form-control" id="optA" name="option_a" required value="<?php echo e($form['option_a']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" id="labelB">Option B</label>
                            <input type="text" class="form-control" id="optB" name="option_b" required value="<?php echo e($form['option_b']); ?>">
                        </div>
                        <div class="col-md-6 opt-cd">
                            <label class="form-label">Option C</label>
                            <input type="text" class="form-control" id="optC" name="option_c" value="<?php echo e($form['option_c']); ?>">
                        </div>
                        <div class="col-md-6 opt-cd">
                            <label class="form-label">Option D</label>
                            <input type="text" class="form-control" id="optD" name="option_d" value="<?php echo e($form['option_d']); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Correct answer</label>
                            <select class="form-select" name="correct_option" id="correctOption">
                                <option value="A" <?php echo $form['correct_option'] === 'A' ? 'selected' : ''; ?>>A</option>
                                <option value="B" <?php echo $form['correct_option'] === 'B' ? 'selected' : ''; ?>>B</option>
                                <option value="C" class="opt-cd" <?php echo $form['correct_option'] === 'C' ? 'selected' : ''; ?>>C</option>
                                <option value="D" class="opt-cd" <?php echo $form['correct_option'] === 'D' ? 'selected' : ''; ?>>D</option>
                            </select>
                        </div>
                    </div>

                    <script>
                    (function () {
                        var type = document.getElementById('questionType');
                        if (!type) { return; }
                        var optA = document.getElementById('optA');
                        var optB = document.getElementById('optB');
                        var optC = document.getElementById('optC');
                        var optD = document.getElementById('optD');
                        var correct = document.getElementById('correctOption');
                        var labelA = document.getElementById('labelA');
                        var labelB = document.getElementById('labelB');

                        function apply() {
                            var tf = type.value === 'true_false';
                            document.querySelectorAll('.opt-cd').forEach(function (el) {
                                el.style.display = tf ? 'none' : '';
                            });
                            optA.readOnly = tf;
                            optB.readOnly = tf;
                            if (tf) {
                                optA.value = 'True';
                                optB.value = 'False';
                                optC.value = '';
                                optD.value = '';
                                labelA.textContent = 'Answer 1';
                                labelB.textContent = 'Answer 2';
                                if (correct.value !== 'A' && correct.value !== 'B') {
                                    correct.value = 'A';
                                }
                            } else {
                                labelA.textContent = 'Option A';
                                labelB.textContent = 'Option B';
                            }
                        }
                        type.addEventListener('change', apply);
                        apply();
                    })();
                    </script>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i><?php echo $editing ? 'Update Question' : 'Add Question'; ?>
                        </button>
                        <?php if ($editing): ?>
                            <a href="<?php echo url('admin/questions.php?exam=' . $selectedExamId); ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-white fw-semibold" data-bs-toggle="collapse"
                 data-bs-target="#aiQuestions" role="button" aria-expanded="true">
                <i class="bi bi-robot me-1 text-primary"></i>AI question generator
                <i class="bi bi-chevron-down float-end small"></i>
            </div>
            <div class="collapse show" id="aiQuestions">
                <div class="card-body">
                    <p class="small text-muted">
                        Difficult university-level questions, matched to the topic and course.
                        Duplicates are rejected automatically. Works offline; uses OpenAI when configured.
                    </p>
                    <form method="post" action="<?php echo url('admin/questions.php?exam=' . $selectedExamId); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="ai_generate">
                        <input type="hidden" name="examination_id" value="<?php echo (int) $selectedExamId; ?>">
                        <div class="row g-2 align-items-end">
                            <div class="col-sm-5">
                                <label class="form-label">Topic / subject</label>
                                <input type="text" class="form-control" name="ai_topic" required
                                       placeholder="e.g. Database Normalization">
                            </div>
                            <div class="col-sm-3">
                                <label class="form-label">Questions</label>
                                <input type="number" class="form-control" name="ai_count" min="1" max="100" value="5">
                            </div>
                            <div class="col-sm-2">
                                <label class="form-label">Type</label>
                                <select class="form-select" name="ai_type">
                                    <option value="mcq">Multiple choice</option>
                                    <option value="true_false">True / False</option>
                                    <option value="mixed">Mixed</option>
                                </select>
                            </div>
                            <div class="col-sm-2">
                                <label class="form-label">Difficulty</label>
                                <select class="form-select" name="ai_difficulty">
                                    <option value="difficult" selected>Difficult</option>
                                    <option value="moderate">Moderate</option>
                                    <option value="easy">Easy</option>
                                </select>
                            </div>
                            <div class="col-sm-2">
                                <label class="form-label">Level</label>
                                <select class="form-select" name="ai_level">
                                    <option value="university" selected>University</option>
                                    <option value="postgraduate">Postgraduate</option>
                                    <option value="professional">Professional</option>
                                </select>
                            </div>
                            <div class="col-sm-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-magic me-1"></i>Generate
                                </button>
                            </div>
                        </div>
                    </form>
                    <hr>
                    <form method="post" action="<?php echo url('admin/questions.php?exam=' . $selectedExamId); ?>" class="d-flex align-items-center gap-2">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="ai_duplicates">
                        <input type="hidden" name="examination_id" value="<?php echo (int) $selectedExamId; ?>">
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-search me-1"></i>Check for duplicate questions
                        </button>
                        <span class="small text-muted">The AI flags questions that look too similar.</span>
                    </form>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold" data-bs-toggle="collapse"
                 data-bs-target="#importQuestions" role="button" aria-expanded="false">
                <i class="bi bi-upload me-1"></i>Import questions from CSV
                <i class="bi bi-chevron-down float-end small"></i>
            </div>
            <div class="collapse" id="importQuestions">
                <div class="card-body">
                    <p class="small text-muted">
                        Upload a CSV with the columns
                        <code>question_text, option_a, option_b, option_c, option_d, correct_option</code>.
                        A header row is optional and <code>correct_option</code> must be A&ndash;D.
                        <a href="<?php echo url('admin/questions.php?template=questions'); ?>">Download a template</a>.
                    </p>
                    <form method="post" enctype="multipart/form-data"
                          action="<?php echo url('admin/questions.php?exam=' . $selectedExamId); ?>">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="import">
                        <input type="hidden" name="examination_id" value="<?php echo (int) $selectedExamId; ?>">
                        <div class="row g-2 align-items-end">
                            <div class="col-sm-8">
                                <label class="form-label">CSV file</label>
                                <input type="file" class="form-control" name="csv_file" accept=".csv,text/csv" required>
                            </div>
                            <div class="col-sm-4">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-upload me-1"></i>Import
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold" data-bs-toggle="collapse"
                 data-bs-target="#copyQuestions" role="button" aria-expanded="false">
                <i class="bi bi-clipboard-plus me-1"></i>Copy questions from another examination
                <i class="bi bi-chevron-down float-end small"></i>
            </div>
            <div class="collapse <?php echo $copyFromExam ? 'show' : ''; ?>" id="copyQuestions">
                <div class="card-body">
                    <p class="small text-muted">
                        Reuse the questions of an existing examination in another one - handy when
                        you run the same paper for different classes or sessions.
                    </p>

                    <form method="get" action="<?php echo url('admin/questions.php'); ?>" class="row g-2 align-items-end mb-3">
                        <input type="hidden" name="exam" value="<?php echo (int) $selectedExamId; ?>">
                        <div class="col-sm-7">
                            <label class="form-label">Copy from</label>
                            <select class="form-select" name="copy_from" onchange="this.form.submit()">
                                <option value="">-- choose an examination --</option>
                                <?php foreach ($examinations as $exam): ?>
                                    <?php if ((int) $exam['id'] === $selectedExamId) { continue; } ?>
                                    <option value="<?php echo (int) $exam['id']; ?>" <?php echo (int) $exam['id'] === $copyFromExamId ? 'selected' : ''; ?>>
                                        <?php echo e($exam['course_code'] . ' - ' . $exam['title']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <button class="btn btn-outline-primary w-100" type="submit">
                                <i class="bi bi-arrow-down-circle me-1"></i>Load questions
                            </button>
                        </div>
                    </form>

                    <?php if ($copyFromExam): ?>
                        <?php if (!$copyFromRows): ?>
                            <div class="alert alert-info mb-0">
                                <strong><?php echo e($copyFromExam['title']); ?></strong> has no questions yet.
                            </div>
                        <?php else: ?>
                        <form method="post" action="<?php echo url('admin/questions.php?exam=' . $selectedExamId); ?>">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="copy_questions">
                            <input type="hidden" name="source_exam_id" value="<?php echo (int) $copyFromExamId; ?>">

                            <div class="border rounded p-2 mb-3" style="max-height: 260px; overflow-y: auto;">
                                <?php $n = 1; foreach ($copyFromRows as $row): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="question_ids[]"
                                               value="<?php echo (int) $row['id']; ?>" id="cq<?php echo (int) $row['id']; ?>" checked>
                                        <label class="form-check-label" for="cq<?php echo (int) $row['id']; ?>">
                                            <span class="text-muted">#<?php echo $n++; ?>.</span>
                                            <?php echo e($row['question_text']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="row g-3 align-items-end">
                                <div class="col-md-5">
                                    <label class="form-label">Copy into</label>
                                    <select class="form-select" name="target_exam_id" required>
                                        <?php foreach ($examinations as $exam): ?>
                                            <option value="<?php echo (int) $exam['id']; ?>" <?php echo (int) $exam['id'] === $selectedExamId ? 'selected' : ''; ?>>
                                                <?php echo e($exam['course_code'] . ' - ' . $exam['title']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-7">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="skip_existing" value="1" id="skipExisting" checked>
                                        <label class="form-check-label" for="skipExisting">
                                            Skip questions that already exist in the target
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="update_total" value="1" id="updateTotal">
                                        <label class="form-check-label" for="updateTotal">
                                            Also update the target's expected question count
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary mt-3">
                                <i class="bi bi-clipboard-check me-1"></i>Copy selected questions
                            </button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px">#</th>
                            <th>Question</th>
                            <th class="text-center">Correct</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$questions): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No questions added yet.</td></tr>
                    <?php else: $n = 1; foreach ($questions as $q): ?>
                        <tr>
                            <td><?php echo $n++; ?></td>
                            <td><?php echo e($q['question_text']); ?>
                                <div class="mt-1">
                                    <span class="badge bg-<?php echo $q['difficulty'] === 'difficult' ? 'danger' : ($q['difficulty'] === 'easy' ? 'success' : 'warning text-dark'); ?>"><?php echo e(isset($q['difficulty']) ? $q['difficulty'] : 'medium'); ?></span>
                                    <?php if (!empty($q['category'])): ?><span class="badge bg-light text-dark border"><?php echo e($q['category']); ?></span><?php endif; ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-dark"><?php echo e($q['correct_option']); ?></span>
                                <div class="small text-muted"><?php echo e($q['option_' . strtolower($q['correct_option'])]); ?></div>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo url('admin/questions.php?exam=' . $selectedExamId . '&edit=' . (int) $q['id']); ?>">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="post" action="<?php echo url('admin/questions.php?exam=' . $selectedExamId); ?>" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int) $q['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="Delete this question?">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
