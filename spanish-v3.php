<?php
declare(strict_types=1);

$csvPath = __DIR__ . '/vocab_input.csv';
$ignorePath = __DIR__ . '/ignore.csv';
$correctPath = __DIR__ . '/correct.csv';
$incorrectPath = __DIR__ . '/incorrect.csv';

const LANGUAGE_ES_TO_EN = 'es_to_en';
const LANGUAGE_EN_TO_ES = 'en_to_es';

/**
 * Reads a single-column CSV file into an associative lookup table.
 *
 * @param string $path Absolute path to the CSV file.
 * @return array<string, bool> Set of keys indexed by their identifier.
 */
function readKeyFile(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $handle = fopen($path, 'r');
    if ($handle === false) {
        return [];
    }

    $keys = [];

    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if (!isset($row[0])) {
            continue;
        }

        $key = trim((string) $row[0]);
        if ($key !== '') {
            $keys[$key] = true;
        }
    }

    fclose($handle);

    return $keys;
}

/**
 * Writes a list of keys to a CSV file, overwriting existing contents.
 *
 * @param string              $path Absolute path to the CSV file.
 * @param array<string, bool> $keys Associative array of keys to persist.
 * @return bool True when the data is successfully written, false otherwise.
 */
function writeKeyFile(string $path, array $keys): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }

    $handle = fopen($path, 'w');
    if ($handle === false) {
        return false;
    }

    foreach (array_keys($keys) as $key) {
        $keyString = trim((string) $key);
        if ($keyString === '') {
            continue;
        }

        if (fputcsv($handle, [$keyString], ',', '"', '\\') === false) {
            fclose($handle);
            return false;
        }
    }

    fclose($handle);

    return true;
}

/**
 * Appends a single key to a CSV file.
 *
 * @param string $path Absolute path to the CSV file.
 * @param string $key  Identifier to append.
 * @return bool True when the key is successfully saved, false otherwise.
 */
function appendKey(string $path, string $key): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        return false;
    }

    $handle = fopen($path, 'a');
    if ($handle === false) {
        return false;
    }

    $result = fputcsv($handle, [$key], ',', '"', '\\');
    fclose($handle);

    return $result !== false;
}

/**
 * Normalizes language codes coming from the client.
 *
 * @param null|string $mode Mode provided by the client.
 * @return string Sanitized language mode.
 */
function normalizeLanguageMode(?string $mode): string
{
    return $mode === LANGUAGE_EN_TO_ES ? LANGUAGE_EN_TO_ES : LANGUAGE_ES_TO_EN;
}

/**
 * Normalizes user answers for case, whitespace, and diacritics.
 *
 * @param string $value Raw answer to clean.
 * @return string Lowercase answer stripped of duplicate whitespace and accents.
 */
function normalizeAnswer(string $value): string
{
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    if (class_exists('Normalizer')) {
        /** @phpstan-ignore-next-line Normalizer is provided by the intl extension. */
        $value = Normalizer::normalize($value, Normalizer::FORM_D) ?? $value;
        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
    }

    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }

    return $value;
}

/**
 * Generates a list of acceptable normalized answers.
 *
 * @param string $value Canonical answer string to expand.
 * @return array<int, string> Normalized answer variants.
 */
function expandAnswerVariants(string $value): array
{
    $parts = preg_split('/[\\/;,]+/u', (string) $value) ?: [];

    if ($parts === []) {
        $parts = [$value];
    }

    $normalized = [];

    foreach ($parts as $part) {
        $clean = normalizeAnswer((string) $part);

        if ($clean === '') {
            continue;
        }

        $normalized[$clean] = true;

        if (str_starts_with($clean, 'to ')) {
            $withoutInfinitive = ltrim(substr($clean, 2));
            if ($withoutInfinitive !== '') {
                $normalized[$withoutInfinitive] = true;
            }
        }
    }

    if ($normalized === []) {
        $fallback = normalizeAnswer($value);
        if ($fallback !== '') {
            $normalized[$fallback] = true;
        }
    }

    return array_keys($normalized);
}

/**
 * Attempts to infer a sensible part of speech using simple heuristics.
 *
 * @param string $english Canonical English translation.
 * @param string $spanish Original Spanish term (used as fallback).
 * @return string Uppercase part-of-speech label.
 */
function determinePartOfSpeech(string $english, string $spanish): string
{
    $candidate = trim(strtolower($english));

    if ($candidate === '') {
        $candidate = trim(strtolower($spanish));
    }

    if ($candidate === '') {
        return 'UNKNOWN';
    }

    if (str_starts_with($candidate, 'to ')) {
        return 'VERB';
    }

    if (preg_match('/\\b(to )/', $candidate)) {
        return 'VERB';
    }

    if (preg_match('/ly$/', $candidate)) {
        return 'ADVERB';
    }

    if (preg_match('/ing$/', $candidate)) {
        return 'GERUND';
    }

    if (preg_match('/(ous|ful|able|ible|al|ary|ate|ive|less|ic|ent|ant|ish|y)$/', $candidate)) {
        return 'ADJECTIVE';
    }

    if (preg_match('/(ment|tion|sion|ness|ity|ism|ship|ence|ance|age|ery|ory|ure)$/', $candidate)) {
        return 'NOUN';
    }

    if (strpos($candidate, ' ') !== false) {
        return 'PHRASE';
    }

    return 'NOUN';
}

/**
 * Classifies verb sub-type (e.g. reflexive, reciprocal).
 *
 * @param string $spanish Original Spanish lexeme.
 * @param string $english Primary English translation.
 * @return string Verb type label.
 */
function classifyVerbType(string $spanish, string $english): string
{
    $spanishLower = trim(mb_strtolower($spanish, 'UTF-8'));
    $englishLower = trim(mb_strtolower($english, 'UTF-8'));

    $hasReflexiveSuffix = preg_match('/se$/u', $spanishLower) === 1;
    $hasInternalSe = preg_match('/\\bse\\b/u', $spanishLower) === 1;

    if ($hasReflexiveSuffix || $hasInternalSe) {
        if (str_contains($englishLower, 'each other') || str_contains($englishLower, 'one another')) {
            return 'Reciprocal verb';
        }

        // Distinguish pronominal verbs that express movement or state change.
        if (preg_match('/^(ir|venir|quedar|poner|sentir|mover|acord|dorm|sentar|bañ|desped|quitar|romp|cas|mud|llam|pelear|enoj|relaj|acostar)/u', $spanishLower)) {
            return 'Reflexive / pronominal verb';
        }

        return 'Pronominal verb';
    }

    if (str_contains($englishLower, 'each other') || str_contains($englishLower, 'one another')) {
        return 'Reciprocal verb';
    }

    // Heuristic: verbs describing motion or state without obvious object.
    if (preg_match('/\\bto (arrive|go|come|exist|sleep|live|die|travel|walk|run|swim|cry|laugh|fall|fly|grow|happen|occur|rest|wait|remain|stay)\\b/u', $englishLower)) {
        return 'Intransitive verb (heuristic)';
    }

    if (preg_match('/\\bto (be|feel|seem|become)\\b/u', $englishLower)) {
        return 'Linking verb';
    }

    // Default assumption: verb likely takes a direct object.
    if ($englishLower !== '' && str_starts_with($englishLower, 'to ')) {
        return 'Transitive verb (likely)';
    }

    return 'Verb';
}

/**
 * Loads the master vocabulary list from the CSV file.
 *
 * @param string $csvPath Absolute path to the vocabulary CSV.
 * @return array<int, array<string, string>> Vocabulary rows keyed by numeric index.
 */
function loadVocabulary(string $csvPath): array
{
    if (!file_exists($csvPath)) {
        return [];
    }

    $handle = fopen($csvPath, 'r');
    if ($handle === false) {
        return [];
    }

    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    if ($headers === false) {
        fclose($handle);
        return [];
    }

    $headers = array_map(static fn ($header) => trim((string) $header), $headers);

    $entries = [];

    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }

        $entry = [];
        foreach ($headers as $index => $header) {
            $entry[$header] = isset($row[$index]) ? trim((string) $row[$index]) : '';
        }

        $spanish = $entry['spanish'] ?? '';
        if ($spanish === '') {
            continue;
        }

        $englishValue = $entry['english'] ?? '';
        $rawId = $entry['id'] ?? '';
        $identifier = $rawId !== '' ? $rawId : $spanish;
        $partOfSpeech = determinePartOfSpeech($englishValue ?? '', $spanish);
        $verbType = $partOfSpeech === 'VERB' ? classifyVerbType($spanish, $englishValue ?? '') : '';

        $entries[] = [
            'id' => $rawId,
            'spanish' => $spanish,
            'english' => $englishValue,
            'other_common_meanings' => $entry['other_common_meanings'] ?? '',
            'key' => $identifier,
            'common_definitions' => $entry['common_definitions'] ?? '',
            'part_of_speech' => $partOfSpeech,
            'verb_type' => $verbType,
        ];
    }

    fclose($handle);

    return $entries;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $payload = file_get_contents('php://input');
    $data = json_decode($payload ?? '', true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid request payload.']);
        exit;
    }

    $action = isset($data['action']) ? trim((string) $data['action']) : 'ignore';
    $key = isset($data['key']) ? trim((string) $data['key']) : '';
    $mode = normalizeLanguageMode($data['languageMode'] ?? null);

    if ($key === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing vocabulary identifier.']);
        exit;
    }

    if ($action === 'ignore') {
        $ignored = readKeyFile($ignorePath);

        if (isset($ignored[$key])) {
            echo json_encode(['ok' => true, 'ignored' => true]);
            exit;
        }

        if (!appendKey($ignorePath, $key)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Unable to update ignore list.']);
            exit;
        }

        echo json_encode(['ok' => true, 'ignored' => true]);
        exit;
    }

    if ($action === 'answer') {
        $guess = isset($data['guess']) ? (string) $data['guess'] : '';

        $vocabulary = loadVocabulary($csvPath);
        $entry = null;

        foreach ($vocabulary as $item) {
            if ($item['key'] === $key) {
                $entry = $item;
                break;
            }
        }

        if ($entry === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Vocabulary entry not found.']);
            exit;
        }

        $targetField = $mode === LANGUAGE_EN_TO_ES ? 'spanish' : 'english';
        $correctAnswer = $entry[$targetField] ?? '';

        $acceptedAnswers = expandAnswerVariants($correctAnswer);
        $normalizedGuess = normalizeAnswer($guess);
        $isCorrect = $normalizedGuess !== '' && in_array($normalizedGuess, $acceptedAnswers, true);

        $correctKeys = readKeyFile($correctPath);
        $incorrectKeys = readKeyFile($incorrectPath);

        if ($isCorrect) {
            unset($incorrectKeys[$key]);
            $correctKeys[$key] = true;

            $writeOk = writeKeyFile($correctPath, $correctKeys) && writeKeyFile($incorrectPath, $incorrectKeys);
            if (!$writeOk) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Unable to record correct answer.']);
                exit;
            }

            echo json_encode([
                'ok' => true,
                'result' => 'correct',
                'correctAnswer' => $correctAnswer,
                'languageMode' => $mode,
            ]);
            exit;
        }

        unset($correctKeys[$key]);
        $incorrectKeys[$key] = true;

        $writeOk = writeKeyFile($correctPath, $correctKeys) && writeKeyFile($incorrectPath, $incorrectKeys);
        if (!$writeOk) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Unable to record incorrect answer.']);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'result' => 'incorrect',
            'correctAnswer' => $correctAnswer,
            'languageMode' => $mode,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported action.']);
    exit;
}

$ignoredKeys = readKeyFile($ignorePath);
$correctKeys = readKeyFile($correctPath);
$incorrectKeys = readKeyFile($incorrectPath);

if (!file_exists($csvPath)) {
    http_response_code(500);
    echo 'CSV file not found.';
    exit;
}

$vocabulary = loadVocabulary($csvPath);
$availableEntries = array_values(array_filter(
    $vocabulary,
    static fn ($entry) => !isset($ignoredKeys[$entry['key']])
));

$correctRows = array_values(array_filter(
    $availableEntries,
    static fn ($entry) => isset($correctKeys[$entry['key']])
));

$incorrectRows = array_values(array_filter(
    $availableEntries,
    static fn ($entry) => isset($incorrectKeys[$entry['key']])
));

$pendingRows = array_values(array_filter(
    $availableEntries,
    static fn ($entry) => !isset($correctKeys[$entry['key']]) && !isset($incorrectKeys[$entry['key']])
));

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Spanish Vocabulary Trainer</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen py-12">
<main class="max-w-5xl mx-auto px-4">
    <header class="text-center">
        <h1 class="text-3xl font-bold text-slate-900">Spanish Vocabulary Trainer</h1>
        <p class="mt-3 text-slate-600">Toggle between tooltips and translation practice to build your vocabulary.</p>
    </header>

    <section class="mt-10 space-y-8">
        <nav class="inline-flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm" role="tablist" aria-label="Vocabulary Views">
            <button
                type="button"
                id="tooltip-tab"
                class="tab-button relative rounded-md px-4 py-2 text-sm font-medium text-slate-700 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                data-tab-target="tooltip-panel"
                aria-selected="true"
                aria-controls="tooltip-panel"
                role="tab"
            >
                Tooltip
            </button>
            <button
                type="button"
                id="practice-tab"
                class="tab-button relative rounded-md px-4 py-2 text-sm font-medium text-slate-600 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                data-tab-target="practice-panel"
                aria-selected="false"
                aria-controls="practice-panel"
                role="tab"
            >
                Practice
            </button>
        </nav>

        <div class="flex flex-wrap justify-center gap-4">
            <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm" role="group" aria-label="Practice direction">
                <button
                    type="button"
                    class="language-toggle-button rounded-md px-4 py-2 text-sm font-medium text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                    data-language-mode="<?php echo LANGUAGE_ES_TO_EN; ?>"
                    aria-pressed="true"
                >
                    Spanish → English
                </button>
                <button
                    type="button"
                    class="language-toggle-button rounded-md px-4 py-2 text-sm font-medium text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                    data-language-mode="<?php echo LANGUAGE_EN_TO_ES; ?>"
                    aria-pressed="false"
                >
                    English → Spanish
                </button>
            </div>
        </div>

        <section id="tooltip-panel" role="tabpanel" aria-labelledby="tooltip-tab">
            <div id="tooltip-app"></div>
        </section>

        <section id="practice-panel" class="hidden" role="tabpanel" aria-labelledby="practice-tab">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="inline-flex rounded-lg border border-slate-200 bg-white p-1 shadow-sm">
                    <button
                        type="button"
                        class="practice-view-button rounded-md px-4 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        data-practice-view="pending"
                        aria-pressed="true"
                    >
                        <span class="label text-slate-700">Practice Queue</span>
                        <span class="ml-2 inline-flex h-6 min-w-[2.25rem] items-center justify-center rounded-full bg-slate-100 px-2 text-xs font-semibold text-slate-600"
                              data-count="pending">0</span>
                    </button>
                    <button
                        type="button"
                        class="practice-view-button rounded-md px-4 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        data-practice-view="incorrect"
                        aria-pressed="false"
                    >
                        <span class="label text-slate-600">Incorrect List</span>
                        <span class="ml-2 inline-flex h-6 min-w-[2.25rem] items-center justify-center rounded-full bg-slate-100 px-2 text-xs font-semibold text-slate-600"
                              data-count="incorrect">0</span>
                    </button>
                    <button
                        type="button"
                        class="practice-view-button rounded-md px-4 py-2 text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        data-practice-view="correct"
                        aria-pressed="false"
                    >
                        <span class="label text-slate-600">Correct List</span>
                        <span class="ml-2 inline-flex h-6 min-w-[2.25rem] items-center justify-center rounded-full bg-slate-100 px-2 text-xs font-semibold text-slate-600"
                              data-count="correct">0</span>
                    </button>
                </div>
                <div id="practice-feedback" class="hidden w-full rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm shadow-sm" role="status" aria-live="polite">
                    <div class="flex items-start justify-between gap-4">
                        <p id="practice-feedback-message" class="font-medium text-slate-700"></p>
                        <button id="practice-feedback-close" type="button" class="rounded-md border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                            Close
                        </button>
                    </div>
                </div>
            </div>
            <p class="mt-4 text-sm text-slate-600">Type the translation for each word. Correct answers move to your correct list, and incorrect ones go to the incorrect list for review.</p>
            <div id="practice-summary" class="hidden mt-4 rounded-lg border border-slate-200 bg-white px-4 py-4 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-800">Submission Summary</h3>
                        <p class="mt-1 text-xs text-slate-500">Review your answers, then close this panel when you're ready to continue.</p>
                    </div>
                    <button id="practice-summary-close" type="button" class="rounded-md border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400">
                        Close
                    </button>
                </div>
                <ul id="practice-summary-list" class="mt-3 space-y-2 text-sm text-slate-700"></ul>
            </div>
            <div id="practice-list" class="mt-6"></div>
        </section>
    </section>
</main>

<script>
    const LANGUAGE_ES_TO_EN = '<?php echo LANGUAGE_ES_TO_EN; ?>';
    const LANGUAGE_EN_TO_ES = '<?php echo LANGUAGE_EN_TO_ES; ?>';

    const tooltipState = {
        entries: <?php echo json_encode($availableEntries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
    };

    const practiceState = {
        pending: <?php echo json_encode($pendingRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        incorrect: <?php echo json_encode($incorrectRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
        correct: <?php echo json_encode($correctRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    };

    const tooltipContainer = document.getElementById('tooltip-app');
    const practiceListContainer = document.getElementById('practice-list');
    const practiceFeedbackContainer = document.getElementById('practice-feedback');
    const practiceFeedbackMessage = document.getElementById('practice-feedback-message');
    const practiceFeedbackClose = document.getElementById('practice-feedback-close');
    const practiceSummary = document.getElementById('practice-summary');
    const practiceSummaryList = document.getElementById('practice-summary-list');
    const practiceSummaryClose = document.getElementById('practice-summary-close');

    const tabButtons = document.querySelectorAll('.tab-button');
    const panels = {
        tooltip: document.getElementById('tooltip-panel'),
        practice: document.getElementById('practice-panel'),
    };

    const practiceViewButtons = document.querySelectorAll('.practice-view-button');
    const practiceCounts = {
        pending: document.querySelector('[data-count="pending"]'),
        incorrect: document.querySelector('[data-count="incorrect"]'),
        correct: document.querySelector('[data-count="correct"]'),
    };

    const languageToggleButtons = document.querySelectorAll('.language-toggle-button');

    let activeTab = 'tooltip';
    let activePracticeView = 'pending';
    let languageMode = LANGUAGE_ES_TO_EN;
    const entryLookup = new Map();

    /**
     * Removes the entry with the provided key from the target array.
     *
     * @param {Array<object>} arr Target array.
     * @param {string} key Vocabulary identifier.
     * @returns {void}
     */
    function removeEntryFromArray(arr, key) {
        const index = arr.findIndex((item) => item.key === key);
        if (index !== -1) {
            arr.splice(index, 1);
        }
    }

    /**
     * Adds an entry to an array if it is not already present.
     *
     * @param {Array<object>} arr Target array.
     * @param {object} entry Vocabulary entry to add.
     * @returns {void}
     */
    function addEntryIfMissing(arr, entry) {
        if (!arr.some((item) => item.key === entry.key)) {
            arr.push(entry);
        }
    }

    /**
     * Removes an entry from all practice arrays.
     *
     * @param {string} key Vocabulary identifier.
     * @returns {void}
     */
    function removeEntryFromPractice(key) {
        removeEntryFromArray(practiceState.pending, key);
        removeEntryFromArray(practiceState.incorrect, key);
        removeEntryFromArray(practiceState.correct, key);
    }

    /**
     * Filters practice entries based on the active language mode.
     *
     * @param {Array<object>} entries Practice entries to evaluate.
     * @returns {Array<object>} Entries valid for the current language mode.
     */
    function filterEntriesForLanguage(entries) {
        return entries.filter((entry) => {
            if (languageMode === LANGUAGE_EN_TO_ES) {
                return typeof entry.english === 'string' && entry.english.trim() !== '';
            }
            return typeof entry.spanish === 'string' && entry.spanish.trim() !== '';
        });
    }

    /**
     * Updates the displayed counters for each practice list.
     *
     * @returns {void}
     */
    function updatePracticeCounts() {
        const pendingCount = filterEntriesForLanguage(practiceState.pending).length;
        const incorrectCount = filterEntriesForLanguage(practiceState.incorrect).length;
        const correctCount = filterEntriesForLanguage(practiceState.correct).length;

        if (practiceCounts.pending) {
            practiceCounts.pending.textContent = pendingCount;
        }
        if (practiceCounts.incorrect) {
            practiceCounts.incorrect.textContent = incorrectCount;
        }
        if (practiceCounts.correct) {
            practiceCounts.correct.textContent = correctCount;
        }
    }

    /**
     * Shows transient feedback after a practice submission.
     *
     * @param {'success'|'error'|'info'} type Feedback style.
     * @param {string} message Text to show to the learner.
     * @returns {void}
     */
    function showPracticeFeedback(type, message) {
        if (!practiceFeedbackContainer || !practiceFeedbackMessage) {
            return;
        }

        practiceFeedbackMessage.textContent = message;
        practiceFeedbackContainer.classList.remove(
            'hidden',
            'border-slate-200',
            'border-emerald-300',
            'border-red-300'
        );
        practiceFeedbackMessage.classList.remove('text-slate-700', 'text-emerald-600', 'text-red-600');

        if (type === 'success') {
            practiceFeedbackContainer.classList.add('border-emerald-300');
            practiceFeedbackMessage.classList.add('text-emerald-600');
        } else if (type === 'error') {
            practiceFeedbackContainer.classList.add('border-red-300');
            practiceFeedbackMessage.classList.add('text-red-600');
        } else {
            practiceFeedbackContainer.classList.add('border-slate-200');
            practiceFeedbackMessage.classList.add('text-slate-700');
        }
    }

    /**
     * Hides the practice feedback panel.
     *
     * @returns {void}
     */
    function hidePracticeFeedback() {
        if (!practiceFeedbackContainer) {
            return;
        }
        practiceFeedbackContainer.classList.add('hidden');
    }

    /**
     * Splits definition text into individual lines.
     *
     * @param {object} entry Vocabulary entry containing tooltip metadata.
     * @returns {Array<string>} Sanitized definition lines.
     */
    function getDefinitionLines(entry) {
        if (!entry || typeof entry.common_definitions !== 'string') {
            return [];
        }

        return entry.common_definitions
            .split('|')
            .map((line) => line.trim())
            .filter((line) => line.length > 0);
    }

    /**
     * Retrieves the part-of-speech label for an entry.
     *
     * @param {object} entry Vocabulary entry containing part-of-speech metadata.
     * @returns {string} Part-of-speech label.
     */
    function getPartOfSpeech(entry) {
        if (!entry || typeof entry.part_of_speech !== 'string' || entry.part_of_speech.trim() === '') {
            return 'UNKNOWN';
        }
        return entry.part_of_speech;
    }

    /**
     * Retrieves the verb classification for an entry.
     *
     * @param {object} entry Vocabulary entry containing verb metadata.
     * @returns {string} Verb classification.
     */
    function getVerbType(entry) {
        if (!entry || typeof entry.verb_type !== 'string') {
            return '';
        }
        return entry.verb_type;
    }

    /**
     * Constructs a tooltip node used within the practice cards.
     *
     * @param {object} entry Vocabulary entry to describe.
     * @returns {HTMLDivElement} Tooltip element.
     */
    function buildPracticeTooltip(entry) {
        const tooltip = document.createElement('div');
        tooltip.className = 'pointer-events-none absolute left-1/2 top-full z-20 mt-3 w-max max-w-xs -translate-x-1/2 rounded-md bg-slate-900 px-3 py-3 text-sm font-medium text-white opacity-0 shadow-lg transition-opacity duration-200 group-hover:opacity-100';

        const posLine = document.createElement('div');
        posLine.className = 'text-xs font-semibold uppercase tracking-wide text-slate-200';
        const partLabel = getPartOfSpeech(entry);
        posLine.textContent = `Part of speech: ${partLabel}`;
        tooltip.appendChild(posLine);

        if (partLabel === 'VERB') {
            const verbNote = getVerbType(entry);
            if (verbNote) {
                const verbLine = document.createElement('div');
                verbLine.className = 'mt-1 text-xs text-slate-200';
                verbLine.textContent = `Verb type: ${verbNote}`;
                tooltip.appendChild(verbLine);
            }
        }

        const definitions = getDefinitionLines(entry);
        if (definitions.length > 0) {
            const definitionList = document.createElement('ul');
            definitionList.className = 'mt-2 list-disc space-y-1 pl-4 text-xs text-slate-200';
            definitions.forEach((definition) => {
                const li = document.createElement('li');
                li.textContent = definition;
                definitionList.appendChild(li);
            });
            tooltip.appendChild(definitionList);
        } else {
            const fallback = document.createElement('div');
            fallback.className = 'mt-2 text-xs text-slate-300';
            fallback.textContent = 'No additional definitions available.';
            tooltip.appendChild(fallback);
        }

        return tooltip;
    }

    /**
     * Renders the tooltip vocabulary list respecting the language toggle.
     *
     * @returns {void}
     */
    function renderTooltipList() {
        tooltipContainer.innerHTML = '';
        const entries = tooltipState.entries;

        if (!Array.isArray(entries) || entries.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'text-center text-slate-500';
            empty.textContent = 'No vocabulary entries available.';
            tooltipContainer.appendChild(empty);
            return;
        }

        const list = document.createElement('ul');
        list.className = 'grid gap-4 sm:grid-cols-2 md:grid-cols-4';

        entries.forEach((entry) => {
            const item = document.createElement('li');
            item.className = 'group relative bg-white border border-slate-200 rounded-lg px-5 py-4 shadow-sm transition-shadow hover:shadow-md';

            const word = document.createElement('span');
            word.className = 'text-lg font-semibold text-slate-900';
            word.textContent = languageMode === LANGUAGE_EN_TO_ES
                ? (entry.english || 'Translation unavailable')
                : entry.spanish;

            const dismiss = document.createElement('button');
            dismiss.type = 'button';
            dismiss.className = 'absolute right-3 top-3 flex h-7 w-7 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-400 transition hover:text-slate-700 hover:border-slate-300';
            dismiss.setAttribute('aria-label', `Hide ${entry.spanish}`);
            dismiss.innerHTML = '&times;';

            dismiss.addEventListener('click', (event) => {
                handleDismissClick(event, entry, dismiss, item);
            });

            const tooltip = document.createElement('div');
            tooltip.className = 'absolute left-1/2 top-full z-10 mt-3 w-max max-w-xs -translate-x-1/2 rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white opacity-0 pointer-events-none transition-opacity duration-200 group-hover:opacity-100';

            const tooltipValue = languageMode === LANGUAGE_EN_TO_ES
                ? entry.spanish
                : (entry.english || 'Translation unavailable');

            const tooltipPrimary = document.createElement('div');
            tooltipPrimary.textContent = tooltipValue || 'Translation unavailable';
            tooltip.appendChild(tooltipPrimary);

            const definitions = getDefinitionLines(entry);
            if (definitions.length > 0) {
                const defList = document.createElement('ul');
                defList.className = 'mt-2 list-disc space-y-1 pl-5 text-xs text-slate-200';
                definitions.forEach((definition) => {
                    const li = document.createElement('li');
                    li.textContent = definition;
                    defList.appendChild(li);
                });
                tooltip.appendChild(defList);
            }

            item.appendChild(word);
            item.appendChild(dismiss);
            item.appendChild(tooltip);
            list.appendChild(item);
        });

        tooltipContainer.appendChild(list);
    }

    /**
     * Handles ignoring an entry from the tooltip list.
     *
     * @param {MouseEvent} event Click event.
     * @param {object} entry Vocabulary entry to ignore.
     * @param {HTMLButtonElement} dismiss Button element that triggered the action.
     * @param {HTMLLIElement} item List item containing the entry.
     * @returns {Promise<void>}
     */
    async function handleDismissClick(event, entry, dismiss, item) {
        event.stopPropagation();
        dismiss.disabled = true;
        dismiss.classList.add('opacity-60', 'cursor-not-allowed');

        try {
            const response = await fetch(window.location.pathname, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'ignore',
                    key: entry.key,
                    languageMode,
                }),
            });

            if (!response.ok) {
                throw new Error(`Request failed with status ${response.status}`);
            }

            const result = await response.json();
            if (!result || !result.ok) {
                throw new Error(result?.error ?? 'Unknown error');
            }

            removeEntryFromArray(tooltipState.entries, entry.key);
            removeEntryFromPractice(entry.key);
            renderTooltipList();
            renderPracticeList();
        } catch (error) {
            console.error('Unable to ignore vocabulary entry:', error);
            dismiss.disabled = false;
            dismiss.classList.remove('opacity-60', 'cursor-not-allowed');
            dismiss.classList.add('text-red-500');
            setTimeout(() => {
                dismiss.classList.remove('text-red-500');
            }, 1500);
        }
    }

    /**
     * Disables or enables all practice inputs and submit buttons.
     *
     * @param {boolean} disabled Whether elements should be disabled.
     * @returns {void}
     */
    function setPracticeInputsDisabled(disabled) {
        if (!practiceListContainer) {
            return;
        }

        const inputs = practiceListContainer.querySelectorAll('.practice-input');
        inputs.forEach((input) => {
            input.disabled = disabled;
        });

        const buttons = practiceListContainer.querySelectorAll('.practice-submit');
        buttons.forEach((button) => {
            const btn = /** @type {HTMLButtonElement} */ (button);
            if (!btn.dataset.label) {
                btn.dataset.label = btn.textContent ?? 'Submit';
            }
            btn.disabled = disabled;
            btn.textContent = disabled ? 'Submitting…' : btn.dataset.label;
        });
    }

    /**
     * Collects all practice inputs that currently contain learner responses.
     *
     * @returns {Array<{entry: object, input: HTMLInputElement, guess: string}>}
     */
    function collectFilledInputs() {
        if (!practiceListContainer) {
            return [];
        }

        const filled = [];
        const inputs = practiceListContainer.querySelectorAll('.practice-input');
        inputs.forEach((element) => {
            const input = /** @type {HTMLInputElement} */ (element);
            const value = input.value.trim();
            if (value === '') {
                return;
            }

            const key = input.getAttribute('data-entry-key');
            if (!key || !entryLookup.has(key)) {
                return;
            }

            const entry = entryLookup.get(key);
            if (!entry) {
                return;
            }

            filled.push({ entry, input, guess: value });
        });

        return filled;
    }

    /**
     * Renders the practice submission summary list.
     *
     * @param {Array<{entry: object, guess: string, status: string, correctAnswer: string, languageMode: string}>} results Submission outcomes.
     * @returns {void}
     */
    function renderPracticeSummary(results) {
        if (!practiceSummary || !practiceSummaryList) {
            return;
        }

        practiceSummaryList.innerHTML = '';

        if (!Array.isArray(results) || results.length === 0) {
            practiceSummary.classList.add('hidden');
            return;
        }

        results.forEach((item) => {
            const modeUsed = item.languageMode === LANGUAGE_EN_TO_ES ? LANGUAGE_EN_TO_ES : LANGUAGE_ES_TO_EN;
            const promptWord = modeUsed === LANGUAGE_EN_TO_ES
                ? (item.entry.english || item.entry.spanish)
                : item.entry.spanish;
            const targetLabel = modeUsed === LANGUAGE_EN_TO_ES ? 'Spanish' : 'English';

            const listItem = document.createElement('li');
            listItem.className = 'rounded-md bg-slate-50 px-3 py-2';

            const title = document.createElement('div');
            title.className = 'text-xs font-semibold uppercase tracking-wide text-slate-500';
            title.textContent = promptWord;

            const detail = document.createElement('div');
            detail.className = 'mt-1 text-sm text-slate-700';

            const answerSpan = document.createElement('span');
            answerSpan.className = `font-semibold ${item.status === 'correct' ? 'text-emerald-600' : 'text-red-600'}`;
            answerSpan.textContent = item.guess || '—';

            const correctSpan = document.createElement('span');
            correctSpan.className = 'font-semibold text-slate-900';
            correctSpan.textContent = item.correctAnswer || '—';

            detail.append('You answered ');
            detail.appendChild(answerSpan);
            detail.append(`. Correct ${targetLabel}: `);
            detail.appendChild(correctSpan);

            listItem.appendChild(title);
            listItem.appendChild(detail);
            practiceSummaryList.appendChild(listItem);
        });

        practiceSummary.classList.remove('hidden');
    }

    /**
     * Hides the practice submission summary.
     *
     * @returns {void}
     */
    function hidePracticeSummary() {
        if (!practiceSummary) {
            return;
        }
        practiceSummary.classList.add('hidden');
        if (practiceSummaryList) {
            practiceSummaryList.innerHTML = '';
        }
    }

    /**
     * Sends a single learner guess to the backend and updates local state.
     *
     * @param {object} entry Vocabulary entry being practiced.
     * @param {string} guess Learner-provided translation.
     * @returns {Promise<{entry: object, guess: string, status: string, correctAnswer: string, languageMode: string}>}
     */
    async function submitPracticeGuess(entry, guess) {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'answer',
                key: entry.key,
                guess,
                languageMode,
            }),
        });

        if (!response.ok) {
            throw new Error(`Request failed with status ${response.status}`);
        }

        const result = await response.json();
        if (!result || !result.ok) {
            throw new Error(result?.error ?? 'Unknown error');
        }

        const modeUsed = result.languageMode === LANGUAGE_EN_TO_ES ? LANGUAGE_EN_TO_ES : LANGUAGE_ES_TO_EN;

        if (result.result === 'correct') {
            removeEntryFromArray(practiceState.pending, entry.key);
            removeEntryFromArray(practiceState.incorrect, entry.key);
            addEntryIfMissing(practiceState.correct, entry);
        } else {
            removeEntryFromArray(practiceState.pending, entry.key);
            removeEntryFromArray(practiceState.correct, entry.key);
            addEntryIfMissing(practiceState.incorrect, entry);
        }

        return {
            entry,
            guess,
            status: result.result,
            correctAnswer: result.correctAnswer,
            languageMode: modeUsed,
        };
    }

    /**
     * Processes every filled practice response in a batch submission.
     *
     * @returns {Promise<void>}
     */
    async function processBatchSubmissions() {
        const filled = collectFilledInputs();
        if (filled.length === 0) {
            showPracticeFeedback('info', 'Enter a translation before submitting.');
            const firstInput = practiceListContainer?.querySelector('.practice-input');
            if (firstInput instanceof HTMLInputElement) {
                firstInput.focus();
            }
            return;
        }

        hidePracticeFeedback();
        hidePracticeSummary();
        setPracticeInputsDisabled(true);

        const results = [];

        for (const item of filled) {
            try {
                const submission = await submitPracticeGuess(item.entry, item.guess);
                results.push(submission);
            } catch (error) {
                console.error('Unable to evaluate answer:', error);
                showPracticeFeedback('error', 'Unable to check that answer. Please try again.');
                renderPracticeList();
                setPracticeInputsDisabled(false);
                return;
            }
        }

        renderPracticeList();
        setPracticeInputsDisabled(false);
        renderPracticeSummary(results);
    }

    /**
     * Produces the practice list contents for the active view.
     *
     * @returns {void}
     */
    function renderPracticeList() {
        updatePracticeCounts();
        practiceListContainer.innerHTML = '';
        entryLookup.clear();

        practiceViewButtons.forEach((button) => {
            const view = button.getAttribute('data-practice-view');
            const isActive = view === activePracticeView;
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            button.classList.toggle('bg-slate-900', isActive);
            button.classList.toggle('text-white', isActive);
            button.classList.toggle('shadow', isActive);

            const label = button.querySelector('.label');
            if (label) {
                label.classList.toggle('text-white', isActive);
                label.classList.toggle('text-slate-700', isActive);
                label.classList.toggle('text-slate-600', !isActive);
            }
        });

        const sourcePool = practiceState[activePracticeView] ?? [];
        const source = filterEntriesForLanguage(sourcePool);

        if (!Array.isArray(source) || source.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'rounded-lg border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-slate-500';

            if (activePracticeView === 'incorrect') {
                empty.textContent = 'You have no words in the incorrect list. Great job!';
            } else if (activePracticeView === 'correct') {
                empty.textContent = 'You have not logged any correct answers yet.';
            } else {
                empty.textContent = 'You have practiced all available words. Refresh later for more.';
            }

            practiceListContainer.appendChild(empty);
            return;
        }

        const list = document.createElement('ul');
        list.className = 'grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4';

        source.forEach((entry) => {
            entryLookup.set(entry.key, entry);

            const item = document.createElement('li');
            item.className = 'relative flex flex-col justify-between gap-4 rounded-lg border border-slate-200 bg-white px-5 py-5 shadow-sm transition-shadow hover:shadow-md';

            const promptWrapper = document.createElement('div');
            promptWrapper.className = 'group relative inline-block';

            const prompt = document.createElement('span');
            prompt.className = 'text-lg font-semibold text-slate-900';
            prompt.textContent = languageMode === LANGUAGE_EN_TO_ES
                ? (entry.english || 'Translation unavailable')
                : entry.spanish;

            promptWrapper.appendChild(prompt);
            promptWrapper.appendChild(buildPracticeTooltip(entry));
            item.appendChild(promptWrapper);

            if (activePracticeView === 'correct') {
                const answer = document.createElement('p');
                answer.className = 'text-sm text-slate-600';

                const label = document.createElement('span');
                label.className = 'font-semibold text-emerald-600';
                label.textContent = languageMode === LANGUAGE_EN_TO_ES
                    ? entry.spanish
                    : (entry.english || 'Translation unavailable');

                answer.appendChild(label);
                item.appendChild(answer);
                list.appendChild(item);
                return;
            }

            const form = document.createElement('form');
            form.className = 'mt-auto flex flex-col gap-3';

            const input = document.createElement('input');
            input.type = 'text';
            input.name = 'translation';
            input.placeholder = languageMode === LANGUAGE_EN_TO_ES
                ? 'Enter Spanish translation'
                : 'Enter English translation';
            input.className = 'practice-input w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-300';
            input.setAttribute('data-entry-key', entry.key);

            const submit = document.createElement('button');
            submit.type = 'submit';
            submit.className = 'practice-submit inline-flex items-center justify-center rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-500';
            submit.dataset.label = 'Submit';
            submit.textContent = 'Submit';

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                processBatchSubmissions();
            });

            form.appendChild(input);
            form.appendChild(submit);
            item.appendChild(form);
            list.appendChild(item);
        });

        practiceListContainer.appendChild(list);
    }

    /**
     * Synchronizes tab button styles and toggles the panels.
     *
     * @param {HTMLElement} button Tab trigger element.
     * @returns {void}
     */
    function activateTab(button) {
        const target = button.getAttribute('data-tab-target');
        const targetKey = target === 'practice-panel' ? 'practice' : 'tooltip';

        if (activeTab === targetKey) {
            return;
        }

        activeTab = targetKey;

        tabButtons.forEach((tab) => {
            const tabTarget = tab.getAttribute('data-tab-target');
            const isActive = tabTarget === target;
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            tab.classList.toggle('bg-slate-900', isActive);
            tab.classList.toggle('text-white', isActive);
            tab.classList.toggle('shadow', isActive);
            tab.classList.toggle('text-slate-600', !isActive);
        });

        if (targetKey === 'practice') {
            panels.practice.classList.remove('hidden');
            panels.tooltip.classList.add('hidden');
        } else {
            panels.tooltip.classList.remove('hidden');
            panels.practice.classList.add('hidden');
        }
    }

    /**
     * Handles practice view button toggles.
     *
     * @param {HTMLElement} button Toggle button element.
     * @returns {void}
     */
    function activatePracticeView(button) {
        const view = button.getAttribute('data-practice-view');
        if (!view || view === activePracticeView) {
            return;
        }

        activePracticeView = view;
        renderPracticeList();
    }

    /**
     * Applies the selected language mode and refreshes UI components.
     *
     * @param {HTMLElement} button Toggle button element.
     * @returns {void}
     */
    function updateLanguageMode(button) {
        const mode = button.getAttribute('data-language-mode');
        const normalizedMode = mode === LANGUAGE_EN_TO_ES ? LANGUAGE_EN_TO_ES : LANGUAGE_ES_TO_EN;

        if (languageMode === normalizedMode) {
            return;
        }

        languageMode = normalizedMode;

        languageToggleButtons.forEach((toggle) => {
            const toggleMode = toggle.getAttribute('data-language-mode');
            const isActive = toggleMode === languageMode;
            toggle.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            toggle.classList.toggle('bg-slate-900', isActive);
            toggle.classList.toggle('text-white', isActive);
            toggle.classList.toggle('shadow', isActive);
            toggle.classList.toggle('text-slate-600', !isActive);
        });

        renderTooltipList();
        renderPracticeList();
    }

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activateTab(button);
        });
    });

    practiceViewButtons.forEach((button) => {
        button.addEventListener('click', () => {
            activatePracticeView(button);
        });
    });

    languageToggleButtons.forEach((button) => {
        button.addEventListener('click', () => {
            updateLanguageMode(button);
        });
    });

    if (practiceFeedbackClose) {
        practiceFeedbackClose.addEventListener('click', () => {
            hidePracticeFeedback();
        });
    }

    if (practiceSummaryClose) {
        practiceSummaryClose.addEventListener('click', () => {
            hidePracticeSummary();
        });
    }

    renderTooltipList();
    renderPracticeList();
</script>
</body>
</html>
