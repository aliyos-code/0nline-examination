<?php
/**
 * Offline "AI" assistant for the Online Examination System.
 *
 * University-level, difficult-question engine. It uses a curated advanced
 * knowledge base, lightweight natural-language tokenising plus a
 * scoring/similarity model to:
 *
 *   - draft difficult, advanced examination questions (MCQ and true/false):
 *     deep understanding, critical thinking, application, analysis,
 *     interpretation and problem-solving - not basic recall
 *   - draft a whole examination (title, instructions, settings, questions)
 *   - NEVER repeat a question: every candidate is compared (exact,
 *     paraphrase and near-duplicate detection, including options) against
 *     the existing questions of the examination and against the other
 *     questions in the same batch; duplicates/invalid items are rejected
 *     and generation retries with a new seed until the requested number
 *     of distinct, valid questions is reached (or the offline bank is
 *     exhausted, in which case fewer are returned - never duplicates)
 *   - spot near-duplicate questions
 *   - produce personalised study tips for a student
 *
 * When an OpenAI key is configured (includes/openai.php), the admin
 * screens use it first for genuinely novel difficult questions and fall
 * back to this engine, topping up any shortfall with this engine, so
 * the requested count is met without duplicates.
 *
 * Everything is deterministic for a given seed, so an administrator can
 * "regenerate" to explore alternative drafts.
 */

if (!function_exists('ai_stopwords')) {
    /**
     * Words ignored when measuring topic relevance.
     *
     * @return array
     */
    function ai_stopwords()
    {
        static $words = [
            'the', 'and', 'for', 'with', 'about', 'into', 'from', 'that', 'this',
            'what', 'which', 'when', 'where', 'who', 'whom', 'whose', 'why', 'how',
            'your', 'have', 'has', 'had', 'are', 'was', 'were', 'will', 'would',
            'is', 'be', 'been', 'being', 'do', 'does', 'did', 'can', 'could',
            'should', 'may', 'might', 'must', 'shall', 'not', 'but', 'all',
            'any', 'each', 'other', 'than', 'then', 'there', 'these', 'those',
            'following', 'given', 'consider', 'exam', 'examination', 'test',
            'quiz', 'course', 'paper', 'set', 'introduction', 'basic',
            'basics', 'overview', 'fundamental', 'fundamentals',
        ];
        return $words;
    }
}

/**
 * Split free text into meaningful lowercase tokens (>= 3 characters).
 *
 * @param  string $text
 * @return array
 */
function ai_tokens($text)
{
    $text  = strtolower((string) $text);
    $parts = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $stop  = ai_stopwords();
    $out   = [];
    foreach ($parts as $word) {
        if (strlen($word) >= 3 && !in_array($word, $stop, true)) {
            $out[$word] = true;
        }
    }
    return array_keys($out);
}

/**
 * A tiny suffix-stemmer so "networks" matches "network".
 *
 * @param  string $word
 * @return string
 */
function ai_stem($word)
{
    $word = (string) $word;
    if (strlen($word) > 4 && substr($word, -3) === 'ies') {
        return substr($word, 0, -3) . 'y';
    }
    foreach (['ing', 'edly', 'ed', 'es', 's'] as $suffix) {
        $len = strlen($suffix);
        if ($suffix === 's' && substr($word, -2) === 'ss') {
            return $word;
        }
        if (strlen($word) > $len + 2 && substr($word, -$len) === $suffix) {
            return substr($word, 0, -$len);
        }
    }
    return $word;
}

/**
 * Canonical form for words that express the same intent, so a paraphrase
 * ("define" vs "explain", "benefit" vs "function") still matches.
 */
function ai_synonym_canonical($word)
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $groups = [
            'explain' => ['define', 'defines', 'defined', 'definition', 'explain', 'explains', 'describe', 'describes', 'describeis', 'identify', 'identifies', 'state', 'states', 'outline', 'outlines'],
            'function' => ['function', 'functions', 'purpose', 'purposes', 'role', 'roles', 'benefit', 'benefits', 'advantage', 'advantages', 'use', 'uses', 'used', 'using', 'useful', 'significance'],
            'compare' => ['difference', 'differences', 'different', 'distinguish', 'distinguishes', 'compare', 'compares', 'contrast', 'contrasts', 'versus', 'relation'],
            'cause' => ['cause', 'causes', 'caused', 'reason', 'reasons', 'why', 'because', 'effect', 'effects', 'result', 'results', 'consequence'],
            'example' => ['example', 'examples', 'instance', 'instances', 'illustration', 'illustrations'],
            'select' => ['select', 'choose', 'chooses', 'identify', 'pick', 'indicate', 'indicates', 'best', 'most', 'correct'],
            'database' => ['database', 'databases', 'db', 'relational'],
            'normalise' => ['normalisation', 'normalization', 'normalise', 'normalize', 'normalised', 'normalized', 'normalising', 'normalizing'],
            'organisation' => ['organisation', 'organization', 'organise', 'organize'],
        ];
        foreach ($groups as $canonical => $words) {
            foreach ($words as $w) {
                $map[$w] = $canonical;
            }
        }
    }
    return isset($map[$word]) ? $map[$word] : $word;
}

/** Normalised question text for exact-duplicate detection. */
function ai_normalize_text($text)
{
    $text = strtolower((string) $text);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', trim($text));
    return $text;
}

/** Stable fingerprint of a question (exact normalised match). */
function ai_question_fingerprint($text)
{
    return md5(ai_normalize_text($text));
}

/**
 * Set of stemmed tokens for similarity scoring.
 *
 * @param  string $text
 * @return array
 */
function ai_token_set($text)
{
    $set = [];
    foreach (ai_tokens($text) as $token) {
        $token = ai_synonym_canonical($token);
        $set[ai_stem($token)] = true;
    }
    return $set;
}

/**
 * Jaccard similarity (0..1) between two strings, used for duplicate detection.
 *
 * @param  string $a
 * @param  string $b
 * @return float
 */
function ai_similarity($a, $b)
{
    $na = ai_normalize_text($a);
    $nb = ai_normalize_text($b);
    if ($na !== '' && $na === $nb) {
        return 1.0;
    }
    $sa = ai_token_set($a);
    $sb = ai_token_set($b);
    if (!$sa || !$sb) {
        return 0.0;
    }
    $intersection = count(array_intersect_key($sa, $sb));
    $union        = count($sa + $sb);
    $jaccard      = $union > 0 ? $intersection / $union : 0.0;
    $contain      = $intersection / min(count($sa), count($sb));
    // Weighted score: catches paraphrases (high containment, lower
    // Jaccard) and slight rewordings (high Jaccard) alike.
    return round(0.6 * $jaccard + 0.4 * $contain, 4);
}

/**
 * Combined duplicate score (0..1) for two questions, given either as text
 * or as rows with question_text / option_a..d. Option overlap is included:
 * the same stem with the same options is a duplicate even if reworded.
 */
function ai_duplicate_score($a, $b)
{
    $ta = is_array($a) ? (isset($a['question_text']) ? $a['question_text'] : '') : (string) $a;
    $tb = is_array($b) ? (isset($b['question_text']) ? $b['question_text'] : '') : (string) $b;
    $textScore = ai_similarity($ta, $tb);
    if ($textScore >= 0.999) {
        return 1.0;
    }
    $oa = '';
    $ob = '';
    if (is_array($a)) {
        foreach (['option_a', 'option_b', 'option_c', 'option_d'] as $c) {
            if (!empty($a[$c])) { $oa .= ' ' . $a[$c]; }
        }
    }
    if (is_array($b)) {
        foreach (['option_a', 'option_b', 'option_c', 'option_d'] as $c) {
            if (!empty($b[$c])) { $ob .= ' ' . $b[$c]; }
        }
    }
    if (trim($oa) === '' || trim($ob) === '') {
        return $textScore;
    }
    $optScore = ai_similarity($oa, $ob);
    return round(0.7 * $textScore + 0.3 * $optScore, 4);
}

/** True if $candidate duplicates any entry of $existing (texts or rows). */
function ai_is_duplicate($candidate, $existing, $threshold = 0.55)
{
    return ai_find_duplicate($candidate, $existing, $threshold) !== null;
}

/**
 * Find the best-matching existing question for $candidate.
 * @return array|null ['text'=>..., 'score'=>float, 'index'=>int]
 */
function ai_find_duplicate($candidate, $existing, $threshold = 0.55)
{
    if (!is_array($existing) || !$existing) {
        return null;
    }
    $candText = is_array($candidate) ? (isset($candidate['question_text']) ? $candidate['question_text'] : '') : (string) $candidate;
    $candNorm = ai_normalize_text($candText);
    if ($candNorm === '') {
        return null;
    }
    $best = null;
    foreach ($existing as $idx => $item) {
        $txt = is_array($item) ? (isset($item['question_text']) ? $item['question_text'] : '') : (string) $item;
        if (ai_normalize_text($txt) === $candNorm) {
            return ['text' => $txt, 'score' => 1.0, 'index' => $idx];
        }
        $score = ai_duplicate_score($candidate, $item);
        if ($score >= $threshold && ($best === null || $score > $best['score'])) {
            $best = ['text' => $txt, 'score' => $score, 'index' => $idx];
        }
    }
    return $best;
}

/** Difficulty / level whitelists shared by the admin screens. */
function ai_difficulty_options()
{
    return ['difficult' => 'Difficult (university)', 'moderate' => 'Moderate', 'easy' => 'Easy'];
}
function ai_level_options()
{
    return ['university' => 'University (undergraduate)', 'postgraduate' => 'Postgraduate', 'professional' => 'Professional'];
}
function ai_sanitise_difficulty($v)
{
    $o = ai_difficulty_options();
    return isset($o[trim((string) $v)]) ? trim((string) $v) : 'difficult';
}
function ai_sanitise_level($v)
{
    $o = ai_level_options();
    return isset($o[trim((string) $v)]) ? trim((string) $v) : 'university';
}

// ---------------------------------------------------------------------------
// Professional-quality validation + de-duplication
// ---------------------------------------------------------------------------

/**
 * Validate one question row for professional university use.
 * @return array List of problems; empty means valid.
 */
function ai_validate_question($q)
{
    $errors = [];
    if (!is_array($q) || !isset($q['question_text'])) {
        return ['Question is missing or malformed.'];
    }
    $text = trim((string) $q['question_text']);
    // No commentary / motivational / assistant phrasing in a question.
    if (preg_match('/^(here (is|are)|sure[,.]|certainly|as an ai|good luck|congratulations|i can help|let me)/i', $text)) {
        $errors[] = 'Question contains commentary instead of examination text.';
    }
    if (stripos($text, 'most closely associated') !== false || stripos($text, 'is a topic studied under') !== false) {
        $errors[] = 'Question uses a meaningless template.';
    }
    if (strlen($text) < 15) {
        $errors[] = 'Question text is too short or incomplete.';
    }
    if (strlen($text) > 2000) {
        $errors[] = 'Question text is too long.';
    }
    if (strpos($text, 'lorem') !== false || strpos($text, '[insert') !== false || strpos($text, 'xxx') !== false) {
        $errors[] = 'Question contains placeholder text.';
    }
    $a = isset($q['option_a']) ? trim((string) $q['option_a']) : '';
    $b = isset($q['option_b']) ? trim((string) $q['option_b']) : '';
    $c = isset($q['option_c']) ? trim((string) $q['option_c']) : '';
    $d = isset($q['option_d']) ? trim((string) $q['option_d']) : '';
    $correct = isset($q['correct_option']) ? strtoupper(trim((string) $q['correct_option'])) : '';
    $isTf = ($c === '' && $d === '');

    if ($isTf) {
        if ($a !== 'True' || $b !== 'False') {
            $errors[] = 'True/False question must use True and False options.';
        }
        if (!in_array($correct, ['A', 'B'], true)) {
            $errors[] = 'True/False question must have correct_option A or B.';
        }
        if (substr($text, -1) !== '.' && substr($text, -1) !== '?') {
            $errors[] = 'True/False statement must end with a full stop.';
        }
    } else {
        if ($a === '' || $b === '' || $c === '' || $d === '') {
            $errors[] = 'Multiple-choice question must have four options.';
        } else {
            $norm = [];
            foreach ([$a, $b, $c, $d] as $opt) {
                $n = ai_normalize_text($opt);
                if (isset($norm[$n])) {
                    $errors[] = 'Options must be distinct.';
                    break;
                }
                $norm[$n] = true;
                if (in_array($n, ['all of the above', 'none of the above', 'all of these', 'none of these', 'all of the listed'], true)) {
                    $errors[] = 'Avoid "all/none of the above" options.';
                }
                if (strlen($opt) > 500) {
                    $errors[] = 'An option is too long.';
                }
            }
        }
        if (!in_array($correct, ['A', 'B', 'C', 'D'], true)) {
            $errors[] = 'Multiple-choice question must have correct_option A-D.';
        }
        if (substr($text, -1) !== '?' && substr($text, -1) !== '.') {
            $errors[] = 'Question must end with a question mark.';
        }
    }
    return $errors;
}

/** Convenience boolean. */
function ai_is_valid_question($q)
{
    return ai_validate_question($q) === [];
}

/** Build a shuffled-option question row from a bank entry. */
function ai_build_question_row($text, $options, $correctLetter)
{
    $letters = ['A', 'B', 'C', 'D'];
    $pairs = [];
    foreach ($options as $i => $opt) {
        $pairs[] = ['text' => $opt, 'correct' => (isset($letters[$i]) && $letters[$i] === $correctLetter)];
    }
    // Deterministic shuffle with the current mt_rand state.
    for ($i = count($pairs) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        $tmp = $pairs[$i]; $pairs[$i] = $pairs[$j]; $pairs[$j] = $tmp;
    }
    $row = ['question_text' => $text];
    $cols = ['option_a', 'option_b', 'option_c', 'option_d'];
    $correct = 'A';
    foreach ($pairs as $i => $pair) {
        $row[$cols[$i]] = $pair['text'];
        if ($pair['correct']) { $correct = $letters[$i]; }
    }
    $row['correct_option'] = $correct;
    return $row;
}

/**
 * Split generated questions into kept vs rejected (invalid, duplicate of
 * an existing question, or duplicate within the batch itself).
 * @return array ['kept'=>array,'rejected'=>array]
 */
function ai_deduplicate_questions($questions, $existing = [], $threshold = 0.55)
{
    $kept = [];
    $rejected = [];
    $seen = is_array($existing) ? array_values($existing) : [];
    // Pre-filter existing to plain texts for fingerprint checks.
    $seenTexts = [];
    foreach ($seen as $s) {
        $seenTexts[] = is_array($s) ? (isset($s['question_text']) ? $s['question_text'] : '') : (string) $s;
    }
    if (!is_array($questions)) {
        return ['kept' => [], 'rejected' => []];
    }
    foreach ($questions as $q) {
        $errs = ai_validate_question($q);
        if ($errs) {
            $rejected[] = ['question' => $q, 'reason' => 'invalid', 'detail' => implode('; ', $errs), 'score' => 0.0, 'duplicate_of' => null];
            continue;
        }
        $dup = ai_find_duplicate($q, $seenTexts, $threshold);
        if ($dup !== null) {
            $rejected[] = ['question' => $q, 'reason' => 'duplicate', 'detail' => $dup['text'], 'score' => $dup['score'], 'duplicate_of' => $dup['text']];
            continue;
        }
        $kept[] = $q;
        // Intra-batch: later items are compared against this one too.
        $seenTexts[] = $q['question_text'];
    }
    return ['kept' => $kept, 'rejected' => $rejected];
}

/**
 * Load existing question texts (for duplicate avoidance) from the DB.
 * With $examId: only that examination. Without: every examination.
 * Returns [] when the DB / table is unavailable (never fatal).
 */
function ai_load_existing_question_texts($pdo, $examId = null)
{
    try {
        if ($examId !== null && (int) $examId > 0) {
            $stmt = $pdo->prepare('SELECT question_text FROM questions WHERE examination_id = ? ORDER BY id ASC');
            $stmt->execute([(int) $examId]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        return $pdo->query('SELECT question_text FROM questions ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    } catch (Error $e) {
        return [];
    }
}

/** Load full question rows (id + text) for one examination. */
function ai_load_exam_questions($pdo, $examId)
{
    try {
        $stmt = $pdo->prepare('SELECT id, question_text FROM questions WHERE examination_id = ? ORDER BY id ASC');
        $stmt->execute([(int) $examId]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    } catch (Error $e) {
        return [];
    }
}

// ---------------------------------------------------------------------------
// Domain detection + knowledge base
// ---------------------------------------------------------------------------

/**
 * Domain keyword fingerprints used to guess the subject of a topic.
 *
 * @return array
 */
function ai_domain_keywords()
{
    return [
        'computer' => ['computer', 'computers', 'programming', 'software', 'hardware',
                       'network', 'networking', 'database', 'normalisation', 'normalization',
                       'sql', 'php', 'java', 'python', 'algorithm', 'html', 'css',
                       'javascript', 'internet', 'security', 'keyboard', 'monitor',
                       'cpu', 'memory', 'server', 'data', 'binary', 'byte', 'bit',
                       'coding', 'code', 'web', 'operating', 'os', 'transaction',
                       'subnet', 'tcp', 'udp', 'oop', 'inheritance', 'polymorphism'],
        'math'     => ['math', 'maths', 'mathematics', 'algebra', 'geometry', 'calculus',
                       'arithmetic', 'number', 'numbers', 'equation', 'fraction', 'angle',
                       'triangle', 'prime', 'probability', 'statistics', 'addition',
                       'multiplication', 'division', 'subtraction', 'percentage', 'ratio',
                       'derivative', 'integral', 'matrix', 'determinant', 'function',
                       'limit', 'logarithm', 'variance', 'eigen'],
        'science'  => ['science', 'physics', 'chemistry', 'biology', 'atom', 'molecule',
                       'cell', 'energy', 'force', 'gravity', 'planet', 'water', 'oxygen',
                       'plant', 'animal', 'human', 'body', 'light', 'sound', 'heat',
                       'element', 'chemical', 'photosynthesis', 'ecosystem', 'enzyme',
                       'genetics', 'thermodynamics', 'acid', 'ph'],
        'english'  => ['english', 'grammar', 'language', 'literature', 'writing', 'essay',
                       'noun', 'verb', 'adjective', 'sentence', 'poem', 'poetry', 'novel',
                       'comprehension', 'vocabulary', 'spelling', 'tense', 'pronoun',
                       'rhetoric', 'semantics', 'metaphor', 'theme', 'tone'],
        'business' => ['business', 'accounting', 'economics', 'commerce', 'management',
                       'marketing', 'finance', 'entrepreneur', 'money', 'bank', 'trade',
                       'profit', 'customer', 'market', 'budget', 'invoice', 'ledger',
                       'elasticity', 'monopoly', 'marginal', 'liquidity', 'inflation'],
        'general'  => ['general', 'knowledge', 'gk', 'history', 'geography', 'civics',
                       'government', 'capital', 'country', 'continent', 'president',
                       'independence', 'flag', 'ocean', 'mountain', 'river', 'constitution',
                       'democracy', 'research', 'validity', 'reliability'],
    ];
}

/**
 * Guess the best-matching domain for a free-text topic.
 *
 * @param  string $topic
 * @return string
 */
function ai_domain($topic)
{
    $tokens = ai_tokens($topic);
    $best   = 'general';
    $bestScore = 0;
    foreach (ai_domain_keywords() as $domain => $keywords) {
        $score = 0;
        foreach ($tokens as $token) {
            $stem = ai_stem($token);
            foreach ($keywords as $keyword) {
                if ($token === $keyword || $stem === ai_stem($keyword)) {
                    $score++;
                    break;
                }
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $best      = $domain;
        }
    }
    return $best;
}

/**
 * The curated multiple-choice question bank, grouped by domain.
 * Each entry: [question, [a, b, c, d], correct_letter, [tags...]].
 *
 * @param  string|null $domain
 * @return array
 */
function ai_mcq_bank($domain = null)
{
    // Advanced, university-level questions: application, analysis,
    // interpretation and problem-solving. First tag = the concept tested
    // (unique per question) so one concept is never tested twice per batch.
    static $bank = [
        'computer' => [
            ['A table Enrolment(StudentID, CourseID, CourseTitle) has primary key (StudentID, CourseID), and CourseTitle depends only on CourseID. Which normal-form rule does this partial dependency violate?', ['First normal form', 'Second normal form', 'Third normal form', 'Boyce-Codd normal form only'], 'B', ['second-normal-form', 'database', 'normalisation']],
            ['A table Orders(OrderID, CustomerID, CustomerAddress) has primary key OrderID, while CustomerAddress depends on CustomerID and not on OrderID. What does this transitive dependency violate?', ['First normal form', 'Second normal form', 'Third normal form', 'Domain integrity'], 'C', ['third-normal-form', 'database', 'normalisation']],
            ['In Boyce-Codd normal form, every non-trivial functional dependency must satisfy which condition?', ['The left-hand side is a superkey', 'Every attribute is atomic', 'Every table has a foreign key', 'The relation has exactly one candidate key'], 'A', ['bcnf', 'database', 'normalisation']],
            ['Two concurrent transactions read the same account balance, both compute a new balance from the old value, and both write it back, so one update silently overwrites the other. Which concurrency anomaly is this?', ['Lost update', 'Phantom read', 'Dirty read', 'Non-repeatable read'], 'A', ['lost-update', 'transaction', 'concurrency']],
            ['A committed sales transaction must remain in the database even if the server crashes immediately afterwards. Which ACID property guarantees this?', ['Atomicity', 'Consistency', 'Isolation', 'Durability'], 'D', ['durability', 'transaction', 'acid']],
            ['Why is a B+ tree index preferred over a hash index when the application frequently issues range queries such as "all orders between two dates"?', ['It stores keys in sorted order, supporting ordered and range searches efficiently', 'It never requires any disk storage', 'It guarantees constant time for every operation regardless of data size', 'It automatically enforces referential integrity'], 'A', ['bplus-index', 'database', 'index']],
            ['During a TCP connection establishment, the client sends SYN, the server replies, and the client finishes the handshake. What is the second packet sent by the server and why?', ['SYN-ACK, acknowledging the client sequence number and sending its own', 'FIN-ACK, requesting a graceful close', 'RST, aborting the connection', 'ACK only, with no sequence number of its own'], 'A', ['tcp-handshake', 'network', 'tcp']],
            ['A network uses CIDR /24 for a subnet. How many usable host addresses does this subnet provide?', ['254', '256', '128', '16'], 'A', ['subnet', 'network', 'cidr']],
            ['A live video-conferencing service tolerates occasional lost frames but cannot tolerate retransmission delay. Which transport protocol best fits this requirement and why?', ['UDP, because it avoids connection setup and retransmission delays', 'TCP, because it guarantees in-order delivery', 'ICMP, because it encrypts every packet', 'HTTP, because it caches all frames'], 'A', ['udp-vs-tcp', 'network', 'transport']],
            ['Flow control and congestion control both involve sending rates, yet they address different problems. How do they differ?', ['Flow control protects a slow receiver; congestion control protects the network from overload', 'They are two names for exactly the same mechanism', 'Flow control protects the network; congestion control protects the receiver', 'Neither affects how data is transmitted'], 'A', ['flow-vs-congestion', 'network', 'tcp']],
            ['Four Coffman conditions are all necessary for deadlock. Which strategy prevents deadlock by breaking mutual exclusion?', ['Making a resource shareable so multiple processes may hold it at once', 'Holding and waiting for more resources', 'Ignoring circular wait entirely', 'Raising process priority'], 'A', ['deadlock-prevention', 'operating-system', 'deadlock']],
            ['What is the primary purpose of a Translation Lookaside Buffer (TLB) in a paged virtual-memory system?', ['To cache recent virtual-to-physical address translations so page-table walks can be avoided', 'To store user programs permanently on disk', 'To encrypt data before it enters RAM', 'To replace the CPU cache entirely'], 'A', ['tlb', 'operating-system', 'paging']],
            ['A graph algorithm must find the shortest path from one source vertex to all others when all edge weights are non-negative, and it must be efficient. Which algorithm fits?', ['Dijkstra\u2019s algorithm', 'Breadth-first search on unweighted graphs only', 'Depth-first search for topological sorting', 'Kruskal\u2019s algorithm for minimum spanning trees'], 'A', ['dijkstra', 'algorithm', 'graphs']],
            ['In SQL, a query groups order rows by customer and must return only groups whose total exceeds 1,000. Why must the total filter use HAVING rather than WHERE?', ['HAVING filters after GROUP BY aggregates are computed; WHERE filters rows before grouping', 'HAVING is simply an older spelling of WHERE', 'WHERE can only be used on numeric columns', 'HAVING automatically sorts the result'], 'A', ['having-vs-where', 'sql', 'database']],
            ['A report must list every customer, including those who have placed no orders, together with their orders where they exist. Which join achieves this?', ['LEFT JOIN, keeping all rows from the left table and nulls where there is no match', 'INNER JOIN, keeping only matching rows from both tables', 'CROSS JOIN, multiplying every row by every other row', 'SELF JOIN, joining a table to itself only'], 'A', ['left-join', 'sql', 'database']],
            ['A class both opens database connections, executes queries, formats e-mail messages and sends them. Under the Single Responsibility Principle, what is the core design fault?', ['The class has more than one reason to change because unrelated concerns are coupled', 'The class uses too few lines of code', 'The class is not serialisable', 'The class lacks a constructor'], 'A', ['srp', 'oop', 'solid']],
            ['A subclass overrides a method so that callers who relied on the parent contract observe different behaviour and their code breaks. Which SOLID principle does this violate?', ['Liskov Substitution Principle', 'Open-Closed Principle', 'Interface Segregation Principle', 'Dependency Inversion Principle'], 'A', ['liskov', 'oop', 'solid']],
            ['Two users choose the same password. Why does storing a per-user random salt alongside the hash defeat rainbow-table attacks?', ['The same password produces a different hash for each user, so one precomputed table cannot match all users', 'The salt encrypts the password reversibly', 'The salt removes the need for a hash entirely', 'The salt makes the password impossible to verify at login'], 'A', ['salt', 'security', 'hashing']],
            ['A malicious page causes a logged-in user\u2019s browser to submit a funds-transfer request to their bank. The attacker never sees the response. Which attack is this and why does it work?', ['Cross-Site Request Forgery, because the browser attaches the victim\u2019s authentication cookies automatically', 'Cross-Site Scripting, because the attacker reads the page content', 'SQL injection, because the attacker edits the database directly', 'Denial of service, because the server runs out of memory'], 'A', ['csrf', 'security', 'web']],
            ['What is the decisive difference between a process and a thread in an operating system?', ['Threads of one process share the same address space; processes are isolated from each other', 'Processes are always faster than threads in every situation', 'Threads cannot be scheduled by the operating system', 'Processes have no shared resources at all'], 'A', ['process-vs-thread', 'operating-system', 'concurrency']],
            ['Two threads increment a shared counter with read-modify-write and no synchronisation, and the final value is sometimes less than expected. What is this defect called?', ['A race condition, because the outcome depends on the timing of the threads', 'A deadlock, because both threads wait forever', 'A livelock, because both threads retry continuously', 'A buffer overflow, because the counter is too large'], 'A', ['race-condition', 'concurrency', 'threads']],
            ['A binary search over a sorted array of one million records and a linear search over the same array differ how, in asymptotic terms?', ['Binary search is O(log n) while linear search is O(n), so the gap grows with n', 'Both are O(1) for any array size', 'Binary search is O(n) and linear search is O(log n)', 'Both are O(n log n)'], 'A', ['binary-search', 'algorithm', 'complexity']],
            ['Hash chaining handles collisions by attaching a list of entries to each bucket. What problem does chaining solve that an unhandled hash table cannot?', ['Two different keys mapping to the same index overwriting each other', 'The need to choose any hash function', 'Excessive memory use in all cases', 'The inability to look up a present key'], 'A', ['hash-chaining', 'data-structure', 'hashing']],
            ['Which pair correctly distinguishes a primary key from a unique key in a relational table?', ['A primary key is NOT NULL and unique and identifies the row; a unique key enforces uniqueness but allows NULLs in many systems', 'They are always identical in every database system', 'A unique key may contain duplicate values freely', 'A primary key may be null for every row'], 'A', ['primary-vs-unique', 'database', 'keys']],
        ],
        'math' => [
            ['If f(x) = sin(x\u00b2), what is f\u2032(x) by the chain rule?', ['2x cos(x\u00b2)', 'cos(x\u00b2)', '2x sin(x\u00b2)', 'x cos(2x)'], 'A', ['chain-rule', 'calculus', 'derivative']],
            ['What is the value of the limit of sin(x)/x as x approaches 0?', ['1', '0', 'Undefined for every function', 'Infinity'], 'A', ['limit', 'calculus']],
            ['Evaluate the definite integral of x\u00b2 from 0 to 1.', ['1/3', '1', '1/2', '3'], 'A', ['integration', 'calculus', 'integral']],
            ['When integrating the product x\u00b7e\u02e3 by parts, which choice of u and dv is the standard effective choice?', ['u = x and dv = e\u02e3 dx, because differentiating x simplifies the remaining integral', 'u = e\u02e3 and dv = x dx', 'u = dx and dv = x e\u02e3 dx', 'u = x e\u02e3 and dv = dx'], 'A', ['integration-by-parts', 'calculus']],
            ['What is the determinant of the matrix [[2, 1], [3, 4]]?', ['5', '8', '11', '2'], 'A', ['determinant', 'matrix', 'algebra']],
            ['A disease affects 1% of a population. A test detects 99% of cases and falsely flags 5% of healthy people. Roughly what proportion of positive results are true cases?', ['About 17%', 'About 99%', 'About 95%', 'About 50%'], 'A', ['bayes', 'probability', 'statistics']],
            ['Two events A and B are mutually exclusive, but neither is impossible. If A occurs, what is the probability of B?', ['0, because mutually exclusive events cannot happen together', '1, because one of them must occur', 'Equal to P(A)', 'Equal to P(A) + P(B)'], 'A', ['mutually-exclusive', 'probability']],
            ['A geometric series has first term 8 and common ratio 1/2. What is the sum of the infinite series?', ['16', '8', '12', '4'], 'A', ['geometric-series', 'series']],
            ['A rectangle has a fixed perimeter of 40 cm. Which dimensions maximise its area?', ['10 cm by 10 cm, giving area 100 cm\u00b2', '20 cm by 0 cm, giving area 0 cm\u00b2', '15 cm by 5 cm, giving area 75 cm\u00b2', '39 cm by 1 cm, giving area 39 cm\u00b2'], 'A', ['optimisation', 'calculus']],
            ['If A and B are independent events with P(A) = 0.3 and P(B) = 0.4, what is P(A and B)?', ['0.12', '0.7', '0.68', '0.3'], 'A', ['independence', 'probability']],
            ['A system of two linear equations has proportional coefficient rows and different constants. What does this imply about the solutions?', ['No solution, because the lines are parallel but distinct', 'Exactly one solution', 'Infinitely many solutions', 'The system is not linear'], 'A', ['linear-systems', 'algebra']],
            ['What is the binomial coefficient C(5, 2), the number of ways to choose 2 items from 5 without order?', ['10', '20', '25', '6'], 'A', ['binomial', 'probability', 'counting']],
            ['A data set has a large positive correlation between study hours and marks. What is the correct interpretation?', ['Higher study hours are associated with higher marks in this data; correlation alone does not prove causation', 'Study hours definitely cause higher marks in every case', 'Study hours have no relationship to marks', 'The marks cause the study hours'], 'A', ['correlation', 'statistics']],
            ['If log\u2081\u2080(x) = 2, what is x?', ['100', '20', '4', '200'], 'A', ['logarithm', 'algebra']],
            ['What is the derivative of x\u00b7ln(x) with respect to x?', ['ln(x) + 1', 'x\u00b7ln(x)', '1/x', 'ln(x) \u2212 1'], 'A', ['product-rule', 'calculus']],
            ['A random variable takes values 0 and 10 each with probability 1/2. What is its expected value?', ['5', '10', '0', '2.5'], 'A', ['expected-value', 'probability']],
        ],
        'science' => [
            ['A solution has a hydrogen-ion concentration of 0.01 mol/L. What is its pH and how is it classified?', ['pH 2, acidic', 'pH 12, alkaline', 'pH 7, neutral', 'pH 0.01, acidic'], 'A', ['ph', 'chemistry', 'acids']],
            ['A 5 kg mass accelerates at 3 m/s\u00b2. What net force is required, by Newton\u2019s second law?', ['15 N', '8 N', '1.67 N', '5 N'], 'A', ['newton-second', 'physics', 'force']],
            ['A 2 kg object moves at 5 m/s. What is its kinetic energy?', ['25 J', '10 J', '50 J', '5 J'], 'A', ['kinetic-energy', 'physics', 'energy']],
            ['In a closed system, 500 J of heat enters and 200 J of work is done by the system. With the convention \u0394U = Q \u2212 W, what is the change in internal energy?', ['+300 J', '+700 J', '\u2212700 J', '\u2212300 J'], 'A', ['first-law', 'thermodynamics', 'energy']],
            ['An enzyme works best at 37\u00b0C but is heated to 90\u00b0C and permanently loses activity. What is the most likely explanation?', ['The high temperature denatured the enzyme, altering its shape and active site', 'The enzyme evaporated completely', 'The substrate became acidic', 'The enzyme mutated its DNA code'], 'A', ['enzyme-denaturation', 'biology', 'enzyme']],
            ['In a dihybrid cross AaBb \u00d7 AaBb, what proportion of offspring are expected to show the dominant phenotype for both traits (assuming complete dominance and independent assortment)?', ['9/16', '3/16', '1/16', '4/16'], 'A', ['dihybrid', 'genetics']],
            ['What is the number of moles in 36 g of water (molar mass 18 g/mol)?', ['2 moles', '18 moles', '0.5 moles', '36 moles'], 'A', ['moles', 'chemistry']],
            ['During transcription, which molecule carries the coding information from DNA to the ribosome?', ['Messenger RNA (mRNA)', 'Transfer RNA as the template', 'Ribosomal DNA', 'ATP'], 'A', ['transcription', 'biology', 'dna']],
            ['How does osmosis differ from simple diffusion?', ['Osmosis is the movement of water across a partially permeable membrane toward the region of higher solute concentration', 'Osmosis requires energy from ATP in all cases', 'Diffusion only occurs in gases', 'Osmosis moves solute rather than solvent'], 'A', ['osmosis', 'biology', 'cells']],
            ['A reaction absorbs heat from its surroundings and the temperature of the surroundings falls. How is the reaction classified?', ['Endothermic, because it takes in thermal energy', 'Exothermic, because it releases thermal energy', 'Athermal, because no energy is involved', 'Catalytic, because temperature changes'], 'A', ['endothermic', 'chemistry', 'energy']],
            ['Why does a metal feel colder than wood at the same room temperature?', ['Metal conducts heat away from the hand faster than wood does', 'Metal is at a lower temperature', 'Wood generates heat', 'Metal has less thermal energy content'], 'A', ['thermal-conduction', 'physics', 'heat']],
            ['A heterozygous plant (Aa) is crossed with a homozygous recessive plant (aa). What proportion of offspring will show the dominant phenotype?', ['1/2', '1', '0', '1/4'], 'A', ['test-cross', 'genetics']],
        ],
        'english' => [
            ['An argument attacks the speaker\u2019s character rather than the evidence for their claim. Which fallacy is this?', ['Ad hominem', 'Straw man', 'False dilemma', 'Slippery slope'], 'A', ['ad-hominem', 'logic', 'argument']],
            ['A writer distorts an opponent\u2019s position into an extreme version and refutes that version instead. Which fallacy is this?', ['Straw man', 'Ad hominem', 'Appeal to ignorance', 'Begging the question'], 'A', ['straw-man', 'argument']],
            ['Which use of ethos, pathos or logos relies on the credibility of the speaker?', ['Ethos', 'Pathos', 'Logos', 'Anaphora'], 'A', ['ethos', 'rhetoric']],
            ['"The wind whispered through the trees" attributes a human action to the wind. Which device is this?', ['Personification', 'Simile', 'Hyperbole', 'Irony'], 'A', ['personification', 'literature']],
            ['Two sentences have the same meaning but one calls a person "thin" and the other "scrawny". What chiefly differs between them?', ['Connotation, because "scrawny" carries a negative evaluative nuance', 'Denotation, because they refer to entirely different objects', 'Syntax, because one is passive', 'Tense, because one is past'], 'A', ['connotation', 'language']],
            ['In the sentence "The report, which was submitted late, was rejected", the clause "which was submitted late" is best described as?', ['A non-defining relative clause, because the report is already identified without it', 'The main clause, because it carries the core event', 'An interrogative clause, because it asks a question', 'A subordinate infinitive, because it begins with "which"'], 'A', ['relative-clause', 'grammar', 'syntax']],
            ['A passage argues that a policy is wrong because its author once made an unrelated error. An analyst should conclude the argument is weak because it commits which error?', ['It judges the claim by the claimant rather than the evidence (ad hominem)', 'It uses a sample that is too large', 'It defines its terms too precisely', 'It draws conclusions from data rather than opinion'], 'A', ['argument-analysis', 'logic']],
            ['Which of the following is a metaphor rather than a simile?', ['"Her mind is a steel trap."', '"Her mind is like a steel trap."', '"Her mind is as strong as a steel trap."', '"Her mind is very strong indeed."'], 'A', ['metaphor', 'literature']],
        ],
        'business' => [
            ['A product sells for 50 per unit with variable cost 30 per unit. Fixed costs are 10,000. How many units must be sold to break even?', ['500 units', '200 units', '2,000 units', '10,000 units'], 'A', ['break-even', 'business', 'accounting']],
            ['A firm\u2019s current ratio is 0.8. What does this signal about liquidity?', ['Current liabilities exceed current assets, so short-term obligations may be hard to meet', 'The firm is highly liquid', 'The firm has no debts at all', 'Liquidity cannot be judged from a ratio'], 'A', ['current-ratio', 'finance', 'accounting']],
            ['In a perfectly competitive market, what pricing power does an individual firm have?', ['It is a price taker and must accept the market price', 'It can set any price it wishes', 'It can restrict output to raise prices without losing all sales', 'It negotiates a unique price with each buyer'], 'A', ['perfect-competition', 'economics']],
            ['When the price of a good rises and the quantity demanded falls proportionally more, demand is described as?', ['Elastic', 'Perfectly inelastic', 'Perfectly elastic', 'Unitary in every case'], 'A', ['elasticity', 'economics']],
            ['Opportunity cost is best defined as?', ['The value of the next best alternative forgone when a choice is made', 'The total money spent on an item', 'The accounting profit of a project', 'A tax deducted from sales'], 'A', ['opportunity-cost', 'economics']],
            ['An asset costs 20,000, has a residual value of 2,000 and a useful life of 6 years. What is the annual straight-line depreciation?', ['3,000 per year', '20,000 per year', '2,000 per year', '6,000 per year'], 'A', ['depreciation', 'accounting']],
            ['Which statement belongs on the balance sheet rather than the income statement?', ['The machinery owned at year-end, as an asset', 'The sales revenue for the year', 'The wages expense for the year', 'The profit for the year'], 'A', ['balance-sheet', 'accounting', 'finance']],
            ['A monopoly differs from perfect competition chiefly in that the monopolist?', ['Is the sole seller and can influence the market price by choosing output', 'Must accept the market price', 'Sells an identical product to many buyers', 'Makes zero economic profit in the long run always'], 'A', ['monopoly', 'economics', 'market']],
        ],
        'general' => [
            ['A survey over-represents urban voters while studying a national electorate. Which research flaw has occurred?', ['Sampling bias, because the sample does not represent the target population', 'High reliability, because the sample is large', 'Perfect validity, because the sample is convenient', 'Causation, because the sample is skewed'], 'A', ['sampling-bias', 'research']],
            ['A test gives consistent results on repeated administration but measures the wrong skill. How should it be described?', ['Reliable but not valid', 'Valid but not reliable', 'Neither reliable nor valid', 'Causal but not predictive'], 'A', ['validity-reliability', 'research']],
            ['In a constitutional system based on separation of powers, what is the characteristic role of the judiciary?', ['To interpret laws and review whether they comply with the constitution', 'To draft all legislation', 'To execute day-to-day administration', 'To set the national budget'], 'A', ['judiciary', 'civics', 'constitution']],
            ['Which statement is a necessary condition rather than a sufficient one? "A candidate must be at least 18 to vote" means:', ['Being 18 is required for voting, but being 18 alone does not guarantee the right to vote', 'Being 18 guarantees the right to vote in all circumstances', 'Voters must be exactly 18 years old', 'Age has no connection to voting'], 'A', ['necessary-sufficient', 'logic']],
            ['A map has a scale of 1:50,000. A distance of 2 cm on the map represents what real distance?', ['1 km', '100 m', '10 km', '50 km'], 'A', ['map-scale', 'geography']],
            ['Which factor most directly controls the expected climate of a region at the same latitude as another?', ['Altitude and proximity to ocean currents, which modify temperature and rainfall', 'The name of the country', 'The number of cities', 'The language spoken'], 'A', ['climate', 'geography']],
        ],
    ];

    return $domain === null ? $bank : (isset($bank[$domain]) ? $bank[$domain] : []);
}

/**
 * University concepts for definition-based generation (unique per term).
 * Each entry: [term, definition, [tags...]].
 */
function ai_concept_bank()
{
    return [
        ['Normalisation', 'The process of organising relational table data to reduce redundancy and improve integrity by decomposing tables according to functional dependencies.', ['computer', 'database', 'normalisation']],
        ['Primary key', 'A column or set of columns whose values uniquely identify each row and may not contain null values.', ['computer', 'database', 'keys']],
        ['Foreign key', 'A column that references the primary key of another table and enforces referential integrity between the tables.', ['computer', 'database', 'keys']],
        ['Transaction', 'A sequence of database operations treated as a single logical unit of work that must satisfy atomicity, consistency, isolation and durability.', ['computer', 'database', 'transaction']],
        ['Deadlock', 'A situation in which two or more processes each hold resources the others need and wait indefinitely for one another.', ['computer', 'operating-system', 'deadlock']],
        ['Cache', 'A small, fast storage layer that keeps copies of frequently used data close to the processor to reduce average access time.', ['computer', 'memory', 'cache']],
        ['Algorithm', 'A finite, unambiguous sequence of steps that solves a specified problem and terminates with the correct result.', ['computer', 'algorithm']],
        ['Recursion', 'A technique in which a function calls itself on smaller subproblems until a base case terminates the chain of calls.', ['computer', 'programming', 'recursion']],
        ['Encapsulation', 'The object-oriented practice of bundling data and the methods that operate on it while hiding internal implementation details.', ['computer', 'oop', 'programming']],
        ['Polymorphism', 'The ability of an interface or base class to take many forms, allowing the same operation to behave appropriately for different types.', ['computer', 'oop', 'programming']],
        ['Derivative', 'The instantaneous rate of change of a function, defined as the limit of the average rate of change over an interval shrinking to zero.', ['math', 'calculus', 'derivative']],
        ['Integral', 'The accumulation of a quantity over an interval, representing the limit of a sum of rectangles under a curve.', ['math', 'calculus', 'integral']],
        ['Matrix', 'A rectangular array of numbers arranged in rows and columns that represents a linear transformation or a system of equations.', ['math', 'algebra', 'matrix']],
        ['Variance', 'A measure of spread equal to the expected squared deviation of a random variable from its mean.', ['math', 'statistics', 'variance']],
        ['Hypothesis', 'A testable proposed explanation of a phenomenon that a study is designed to support or refute.', ['general', 'research', 'science']],
        ['Enzyme', 'A biological catalyst, usually a protein, that lowers the activation energy of a reaction without being consumed.', ['science', 'biology', 'enzyme']],
        ['Osmosis', 'The net movement of solvent across a partially permeable membrane from a region of lower solute concentration to higher solute concentration.', ['science', 'biology', 'cells']],
        ['Ecosystem', 'A community of living organisms interacting with their physical environment as a functional system.', ['science', 'biology', 'ecosystem']],
        ['Metaphor', 'A figure of speech that describes one thing as if it were another without using the words like or as.', ['english', 'literature', 'metaphor']],
        ['Theme', 'The central underlying message or insight that a literary work explores through its characters and events.', ['english', 'literature', 'theme']],
        ['Connotation', 'The emotional or cultural associations a word evokes beyond its literal denotation.', ['english', 'language', 'semantics']],
        ['Liquidity', 'The ease with which an asset can be converted into cash without significantly affecting its value.', ['business', 'finance', 'liquidity']],
        ['Inflation', 'A sustained general increase in the price level that reduces the purchasing power of money over time.', ['business', 'economics', 'inflation']],
        ['Monopoly', 'A market structure in which a single seller controls supply and can influence price by controlling output.', ['business', 'economics', 'monopoly']],
        ['Validity', 'The extent to which a measurement or test actually measures what it claims to measure.', ['general', 'research', 'validity']],
        ['Reliability', 'The consistency of a measurement: whether repeated administration yields the same result under the same conditions.', ['general', 'research', 'reliability']],
        ['Democracy', 'A system of government in which citizens exercise power through voting, representation and protected rights.', ['general', 'civics', 'constitution']],
        ['Constitution', 'The fundamental legal framework that establishes a state\u2019s structure, powers, rights and governing principles.', ['general', 'civics', 'constitution']],
    ];
}

/**
 * Curated true/false statements grouped by domain.
 * Each entry: [statement, is_true, [tags...]].
 *
 * @return array
 */
function ai_truefalse_bank()
{
    return [
        ['A relation in Boyce-Codd normal form automatically satisfies third normal form.', true, ['computer', 'database', 'normalisation']],
        ['A foreign key may reference a column that is neither a primary key nor a unique key.', false, ['computer', 'database', 'keys']],
        ['The HAVING clause filters individual rows before grouping is applied.', false, ['computer', 'sql', 'database']],
        ['Dijkstra\u2019s algorithm guarantees the shortest path even when graph edges have negative weights.', false, ['computer', 'algorithm']],
        ['A salted password hash is reversible, so a stolen database reveals the original passwords.', false, ['computer', 'security', 'hashing']],
        ['Flow control in TCP is primarily concerned with preventing the network itself from becoming congested.', false, ['computer', 'network', 'tcp']],
        ['A derivative measures the average rate of change over a fixed interval rather than an instantaneous rate.', false, ['math', 'calculus', 'derivative']],
        ['Two mutually exclusive events with non-zero probability can both occur in a single trial.', false, ['math', 'probability']],
        ['A strong correlation between two variables proves that one causes the other.', false, ['math', 'statistics']],
        ['The determinant of a singular matrix is zero, and the matrix has no inverse.', true, ['math', 'matrix']],
        ['Confidence intervals become wider as the sample size increases, all else being equal.', false, ['math', 'statistics']],
        ['Enzymes are used up and destroyed during the reactions they catalyse.', false, ['science', 'biology', 'enzyme']],
        ['Osmosis moves solute from a region of lower concentration to a higher concentration.', false, ['science', 'biology', 'cells']],
        ['An endothermic reaction releases thermal energy to its surroundings.', false, ['science', 'chemistry', 'energy']],
        ['A metaphor explicitly uses the words "like" or "as".', false, ['english', 'literature', 'metaphor']],
        ['Ethos refers to the emotional appeal of an argument rather than the speaker\u2019s credibility.', false, ['english', 'rhetoric']],
        ['An ad hominem argument refutes a claim by attacking the claimant\u2019s evidence rather than their character.', false, ['english', 'argument']],
        ['In perfect competition, an individual firm can set the market price without losing sales.', false, ['business', 'economics', 'market']],
        ['A current ratio below 1 means current assets exceed current liabilities.', false, ['business', 'finance', 'accounting']],
        ['Opportunity cost is the accounting profit forgone when a choice is made.', false, ['business', 'economics']],
        ['A reliable test necessarily measures the intended construct.', false, ['general', 'research', 'validity']],
        ['A sample that systematically over-represents one subgroup is free from bias because it is large.', false, ['general', 'research']],
    ];
}

// ---------------------------------------------------------------------------
// Generation
// ---------------------------------------------------------------------------

/**
 * Relevance score of a knowledge-base entry to a set of topic tokens.
 *
 * @param  array $tags
 * @param  array $tokens
 * @return int
 */
function ai_relevance($tags, $tokens)
{
    $score = 0;
    foreach ($tokens as $token) {
        $stem = ai_stem($token);
        foreach ($tags as $tag) {
            if ($token === $tag || $stem === ai_stem($tag)) {
                $score += 2;
                break;
            }
            if (strpos($tag, $token) !== false || strpos($token, $tag) !== false) {
                $score += 1;
                break;
            }
        }
    }
    return $score;
}

/**
 * Generate multiple-choice questions for a topic.
 *
 * @param  string $topic
 * @param  int    $count
 * @param  int    $seed
 * @return array  List of ['question_text','option_a','option_b','option_c','option_d','correct_option']
 */
function ai_generate_mcq($topic, $count, $seed, $existing = [], $difficulty = 'difficult', $academicLevel = 'university')
{
    mt_srand((int) $seed);
    $domain = ai_domain($topic);
    $tokens = ai_tokens($topic);
    $pool   = ai_mcq_bank($domain);
    $existingTexts = [];
    foreach ((array) $existing as $e) {
        $existingTexts[] = is_array($e) ? (isset($e['question_text']) ? $e['question_text'] : '') : (string) $e;
    }

    // Rank by relevance, randomised within equal-relevance tiers so
    // "regenerate" yields a different subset and order.
    $scored = [];
    foreach ($pool as $idx => $item) {
        $scored[] = ['item' => $item, 'score' => ai_relevance($item[3], $tokens) + (in_array($domain, $item[3], true) ? 1 : 0), 'rand' => mt_rand(0, 1000000)];
    }
    usort($scored, function ($x, $y) {
        if ($x['score'] !== $y['score']) {
            return $x['score'] < $y['score'] ? 1 : -1;
        }
        return $x['rand'] < $y['rand'] ? -1 : 1;
    });

    $questions = [];
    $usedConcepts = [];
    foreach ($scored as $entry) {
        if (count($questions) >= $count) {
            break;
        }
        $item = $entry['item'];
        $concept = isset($item[3][0]) ? $item[3][0] : $item[0];
        if (isset($usedConcepts[$concept])) {
            continue;
        }
        $row = ai_build_question_row($item[0], $item[1], $item[2]);
        if (ai_validate_question($row) !== []) {
            continue;
        }
        // Reject exact / paraphrase / near-duplicates vs existing + batch.
        $checkAgainst = array_merge($existingTexts, array_map(function ($q) { return $q['question_text']; }, $questions));
        if (ai_is_duplicate($row, $checkAgainst, 0.55)) {
            continue;
        }
        $questions[] = $row;
        $usedConcepts[$concept] = true;
    }

    if (count($questions) < $count) {
        $fillers = ai_synthesize_mcq($topic, $domain, $count - count($questions), (int) $seed + 7, array_merge($existingTexts, array_map(function ($q) { return $q['question_text']; }, $questions)));
        foreach ($fillers as $f) {
            if (count($questions) >= $count) { break; }
            $checkAgainst = array_merge($existingTexts, array_map(function ($q) { return $q['question_text']; }, $questions));
            if (ai_validate_question($f) === [] && !ai_is_duplicate($f, $checkAgainst, 0.55)) {
                $questions[] = $f;
            }
        }
    }

    // Interleave question structures so the same pattern does not repeat.
    if (count($questions) > 1) {
        for ($i = count($questions) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $tmp = $questions[$i]; $questions[$i] = $questions[$j]; $questions[$j] = $tmp;
        }
    }
    return $questions;
}

/**
 * Build synthetic "concept matching" questions when the curated bank is short.
 *
 * @param  string $topic
 * @param  string $domain
 * @param  int    $count
 * @param  int    $seed
 * @return array
 */
function ai_synthesize_mcq($topic, $domain, $count, $seed, $existing = [])
{
    if ($count <= 0) {
        return [];
    }
    mt_srand((int) $seed);
    $tokens = ai_tokens($topic);
    $concepts = ai_concept_bank();
    $scored = [];
    foreach ($concepts as $c) {
        $scored[] = ['c' => $c, 'score' => ai_relevance($c[2], $tokens), 'rand' => mt_rand(0, 1000000)];
    }
    usort($scored, function ($x, $y) {
        if ($x['score'] !== $y['score']) {
            return $x['score'] < $y['score'] ? 1 : -1;
        }
        return $x['rand'] < $y['rand'] ? -1 : 1;
    });

    $templates = [
        'Which statement best defines %s?',
        'Which of the following is the most accurate description of %s?',
        'When a student describes %s, which explanation is academically correct?',
        'Which of the following best characterises %s?',
    ];
    $out = [];
    $usedDefs = [];
    foreach ($scored as $entry) {
        if (count($out) >= $count) {
            break;
        }
        $c = $entry['c'];
        if (isset($usedDefs[$c[0]])) {
            continue;
        }
        // Distractors: definitions of three other concepts.
        $distractors = [];
        foreach ($scored as $other) {
            if ($other['c'][0] === $c[0]) { continue; }
            $distractors[] = $other['c'][1];
            if (count($distractors) >= 12) { break; }
        }
        if (count($distractors) < 3) {
            continue;
        }
        $picks = [];
        $poolDefs = $distractors;
        for ($i = count($poolDefs) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $tmp = $poolDefs[$i]; $poolDefs[$i] = $poolDefs[$j]; $poolDefs[$j] = $tmp;
        }
        $picks = array_slice($poolDefs, 0, 3);
        $picks[] = $c[1];
        // Shuffle option order.
        for ($i = count($picks) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $tmp = $picks[$i]; $picks[$i] = $picks[$j]; $picks[$j] = $tmp;
        }
        $tpl = $templates[mt_rand(0, count($templates) - 1)];
        $text = sprintf($tpl, $c[0]);
        $letters = ['A', 'B', 'C', 'D'];
        $row = ['question_text' => $text];
        $cols = ['option_a', 'option_b', 'option_c', 'option_d'];
        $correct = 'A';
        foreach ($picks as $i => $opt) {
            $row[$cols[$i]] = $opt;
            if ($opt === $c[1]) { $correct = $letters[$i]; }
        }
        $row['correct_option'] = $correct;
        if (ai_validate_question($row) !== []) {
            continue;
        }
        $check = array_merge((array) $existing, array_map(function ($q) { return $q['question_text']; }, $out));
        if (ai_is_duplicate($row, $check, 0.55)) {
            continue;
        }
        $out[] = $row;
        $usedDefs[$c[0]] = true;
    }
    return $out;
}

/**
 * Generate true/false questions for a topic.
 *
 * @param  string $topic
 * @param  int    $count
 * @param  int    $seed
 * @return array
 */
function ai_generate_truefalse($topic, $count, $seed, $existing = [])
{
    mt_srand((int) $seed);
    $domain = ai_domain($topic);
    $tokens = ai_tokens($topic);
    $pool   = ai_truefalse_bank();
    $existingTexts = [];
    foreach ((array) $existing as $e) {
        $existingTexts[] = is_array($e) ? (isset($e['question_text']) ? $e['question_text'] : '') : (string) $e;
    }

    $scored = [];
    foreach ($pool as $item) {
        $scored[] = ['item' => $item, 'score' => ai_relevance($item[2], $tokens) + (in_array($domain, $item[2], true) ? 3 : 0), 'rand' => mt_rand(0, 1000000)];
    }
    usort($scored, function ($x, $y) {
        if ($x['score'] !== $y['score']) {
            return $x['score'] < $y['score'] ? 1 : -1;
        }
        return $x['rand'] < $y['rand'] ? -1 : 1;
    });

    $out = [];
    foreach ($scored as $entry) {
        if (count($out) >= $count) {
            break;
        }
        $item = $entry['item'];
        $row = [
            'question_text'   => $item[0],
            'option_a'        => 'True',
            'option_b'        => 'False',
            'option_c'        => '',
            'option_d'        => '',
            'correct_option'  => $item[1] ? 'A' : 'B',
        ];
        if (ai_validate_question($row) !== []) {
            continue;
        }
        $check = array_merge($existingTexts, array_map(function ($q) { return $q['question_text']; }, $out));
        if (ai_is_duplicate($row, $check, 0.55)) {
            continue;
        }
        $out[] = $row;
    }

    // Concept-based fillers (unique per term, mixed true/false).
    if (count($out) < $count) {
        $concepts = ai_concept_bank();
        $cscored = [];
        foreach ($concepts as $c) {
            $cscored[] = ['c' => $c, 'score' => ai_relevance($c[2], $tokens), 'rand' => mt_rand(0, 1000000)];
        }
        usort($cscored, function ($x, $y) {
            if ($x['score'] !== $y['score']) {
                return $x['score'] < $y['score'] ? 1 : -1;
            }
            return $x['rand'] < $y['rand'] ? -1 : 1;
        });
        foreach ($cscored as $entry) {
            if (count($out) >= $count) {
                break;
            }
            $c = $entry['c'];
            $isTrue = (count($out) % 2 === 0);
            if ($isTrue) {
                $text = $c[0] . ' is ' . lcfirst($c[1]);
                if (substr($text, -1) !== '.') { $text .= '.'; }
                $correct = 'A';
            } else {
                // Swap in another concept's definition.
                $other = null;
                foreach ($cscored as $o) {
                    if ($o['c'][0] !== $c[0]) { $other = $o['c']; break; }
                }
                if ($other === null) { continue; }
                $text = $c[0] . ' is ' . lcfirst($other[1]);
                if (substr($text, -1) !== '.') { $text .= '.'; }
                $correct = 'B';
            }
            $row = [
                'question_text'  => $text,
                'option_a'       => 'True',
                'option_b'       => 'False',
                'option_c'       => '',
                'option_d'       => '',
                'correct_option' => $correct,
            ];
            if (ai_validate_question($row) !== []) {
                continue;
            }
            $check = array_merge($existingTexts, array_map(function ($q) { return $q['question_text']; }, $out));
            if (ai_is_duplicate($row, $check, 0.55)) {
                continue;
            }
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Generate a batch of questions of the requested type.
 *
 * @param  string $topic
 * @param  int    $count
 * @param  string $type    mcq|true_false|mixed
 * @param  int    $seed
 * @return array
 */
function ai_generate_questions($topic, $count, $type = 'mcq', $seed = 0, $existing = [], $difficulty = 'difficult', $academicLevel = 'university')
{
    $count = max(1, min(100, (int) $count));
    if ($seed <= 0) {
        $seed = random_int(1, 1000000000);
    }
    if ($type !== 'true_false' && $type !== 'mixed') {
        $type = 'mcq';
    }
    $difficulty = ai_sanitise_difficulty($difficulty);
    $academicLevel = ai_sanitise_level($academicLevel);

    $existingTexts = [];
    foreach ((array) $existing as $e) {
        $existingTexts[] = is_array($e) ? (isset($e['question_text']) ? $e['question_text'] : '') : (string) $e;
    }

    // Retry loop: a candidate batch is filtered against the database
    // questions and itself; rejected duplicates are regenerated with a
    // new seed until the requested number of unique valid questions is
    // reached (or the offline pools are exhausted).
    $kept = [];
    $attempt = 0;
    $maxAttempts = 8;
    while (count($kept) < $count && $attempt < $maxAttempts) {
        $needed = $count - count($kept);
        $batchSeed = (int) $seed + $attempt * 104729;
        $avoid = array_merge($existingTexts, array_map(function ($q) { return $q['question_text']; }, $kept));

        if ($type === 'mcq') {
            $candidates = ai_generate_mcq($topic, $needed, $batchSeed, $avoid, $difficulty, $academicLevel);
        } elseif ($type === 'true_false') {
            $candidates = ai_generate_truefalse($topic, $needed, $batchSeed, $avoid);
        } else {
            $tfCount = (int) ceil($needed / 2);
            $mcqCount = $needed - $tfCount;
            $tf = ai_generate_truefalse($topic, $tfCount, $batchSeed + 101, $avoid);
            $avoid2 = array_merge($avoid, array_map(function ($q) { return $q['question_text']; }, $tf));
            $mcq = ai_generate_mcq($topic, $mcqCount, $batchSeed + 202, $avoid2, $difficulty, $academicLevel);
            $candidates = array_merge($tf, $mcq);
            // Interleave structures.
            for ($i = count($candidates) - 1; $i > 0; $i--) {
                mt_srand($batchSeed + 303);
                $j = mt_rand(0, $i);
                $tmp = $candidates[$i]; $candidates[$i] = $candidates[$j]; $candidates[$j] = $tmp;
            }
        }

        $filtered = ai_deduplicate_questions($candidates, $avoid, 0.55);
        $before = count($kept);
        foreach ($filtered['kept'] as $k) {
            if (count($kept) >= $count) { break; }
            $kept[] = $k;
        }
        if (count($kept) === $before) {
            $attempt++;
            // Pools exhausted for this seed family: give up rather than duplicate.
            if ($attempt >= 3 && count($kept) === 0) {
                break;
            }
        } else {
            $attempt++;
        }
    }
    return array_slice($kept, 0, $count);
}

/**
 * Draft a complete examination proposal from a short specification.
 *
 * @param  array $spec  Keys: topic, course_name, course_code, count, type, duration, pass_mark, seed
 * @return array
 */
function ai_generate_exam($spec)
{
    $topic      = isset($spec['topic']) ? trim((string) $spec['topic']) : '';
    $count      = isset($spec['count']) ? max(1, min(100, (int) $spec['count'])) : 10;
    $type       = isset($spec['type']) ? $spec['type'] : 'mcq';
    $seed       = isset($spec['seed']) ? (int) $spec['seed'] : 0;
    $courseName = isset($spec['course_name']) ? trim((string) $spec['course_name']) : '';
    $courseCode = isset($spec['course_code']) ? trim((string) $spec['course_code']) : '';
    $duration   = isset($spec['duration']) && (int) $spec['duration'] > 0
        ? (int) $spec['duration'] : max(10, $count * 2);
    $passMark   = isset($spec['pass_mark']) ? (int) $spec['pass_mark'] : 50;
    $existing   = isset($spec['existing']) && is_array($spec['existing']) ? $spec['existing'] : [];
    $difficulty = isset($spec['difficulty']) ? ai_sanitise_difficulty($spec['difficulty']) : 'difficult';
    $level      = isset($spec['academic_level']) ? ai_sanitise_level($spec['academic_level']) : 'university';
    $rejectedCount = 0;

    if (isset($spec['pregenerated_questions']) && is_array($spec['pregenerated_questions'])) {
        $filtered = ai_deduplicate_questions(array_slice($spec['pregenerated_questions'], 0, $count), $existing, 0.55);
        $questions = $filtered['kept'];
        $rejectedCount = count($filtered['rejected']);
        if (count($questions) < $count) {
            $topped = ai_generate_questions($topic, $count - count($questions), $type, $seed + 999, array_merge($existing, array_map(function ($q) { return $q['question_text']; }, $questions)), $difficulty, $level);
            foreach ($topped as $t) {
                if (count($questions) >= $count) { break; }
                $questions[] = $t;
            }
        }
    } else {
        $questions = ai_generate_questions($topic, $count, $type, $seed, $existing, $difficulty, $level);
    }

    $title = $topic !== '' ? ucwords($topic) . ' Examination' : 'Generated Examination';
    if ($courseCode === '') {
        // Build a sensible code from the initials of the meaningful words.
        $abbr = '';
        foreach (preg_split('/\s+/', $topic) as $word) {
            $clean = preg_replace('/[^A-Za-z0-9]/', '', $word);
            if ($clean === '' || in_array(strtolower($word), ai_stopwords(), true)) {
                continue;
            }
            $abbr .= strtoupper($clean[0]);
            if (strlen($abbr) >= 3) {
                break;
            }
        }
        if ($abbr === '') {
            $abbr = 'GEN';
        }
        $courseCode = $abbr . '101';
    }
    if ($courseName === '') {
        $courseName = $topic !== '' ? ucwords($topic) : 'General Studies';
    }

    return [
        'topic'            => $topic,
        'type'             => $type,
        'title'            => $title,
        'course_name'      => $courseName,
        'course_code'      => $courseCode,
        'instructions'     => "Answer all questions. Each question carries equal marks.\n"
            . 'The examination is timed; it submits automatically when the time runs out.',
        'duration_minutes' => $duration,
        'total_questions'  => count($questions),
        'pass_mark'        => $passMark,
        'questions'        => $questions,
        'seed'             => $seed,
        'difficulty'       => $difficulty,
        'academic_level'   => $level,
        'rejected_duplicates' => $rejectedCount,
    ];
}

/**
 * Find near-duplicate questions within a list.
 *
 * @param  array $questions  Each item must expose question_text and id.
 * @param  float $threshold  0..1 (default 0.62)
 * @return array  List of [idA, textA, idB, textB, score]
 */
function ai_duplicate_pairs($questions, $threshold = 0.55)
{
    $pairs = [];
    $n     = count($questions);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $score = ai_similarity($questions[$i]['question_text'], $questions[$j]['question_text']);
            if ($score >= $threshold) {
                $pairs[] = [
                    'a_id'    => $questions[$i]['id'],
                    'a_text'  => $questions[$i]['question_text'],
                    'b_id'    => $questions[$j]['id'],
                    'b_text'  => $questions[$j]['question_text'],
                    'score'   => round($score * 100),
                ];
            }
        }
    }
    return $pairs;
}

/**
 * Produce personalised study tips based on a student's result.
 *
 * @param  float $percentage
 * @param  int   $passMark
 * @param  int   $correct
 * @param  int   $total
 * @param  array $weakTokens  Words appearing in questions the student missed.
 * @return array
 */
function ai_study_tips($percentage, $passMark, $correct, $total, $weakTokens = [])
{
    $tips = [];
    $percentage = (float) $percentage;

    if ($percentage >= 90) {
        $tips[] = 'Outstanding result! Keep up the consistent revision that is clearly working.';
    } elseif ($percentage >= (float) $passMark) {
        $tips[] = 'Well done, you passed. Review the few questions you missed to push towards a distinction.';
    } else {
        $tips[] = 'You did not reach the pass mark this time - schedule short, regular study sessions and try again.';
    }

    if ($total > 0 && $correct < $total) {
        $missed = $total - $correct;
        $tips[] = 'You missed ' . $missed . ' of ' . $total . ' questions. Re-read those topics before your next attempt.';
    }

    if ($weakTokens) {
        $labels = array_slice(array_values(array_unique($weakTokens)), 0, 5);
        if ($labels) {
            $tips[] = 'Focus your revision on: ' . implode(', ', $labels) . '.';
        }
    }

    $tips[] = 'Practise with past questions and explain each answer in your own words - it improves recall.';
    return $tips;
}
