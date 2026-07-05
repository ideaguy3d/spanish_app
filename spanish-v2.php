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

        $rawId = $entry['id'] ?? '';
        $identifier = $rawId !== '' ? $rawId : $spanish;

        $entries[] = [
            'id' => $rawId,
            'spanish' => $spanish,
            'english' => $entry['english'] ?? '',
            'other_common_meanings' => $entry['other_common_meanings'] ?? '',
            'key' => $identifier,
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
                <p id="practice-feedback" class="hidden text-sm font-medium text-slate-600" role="status" aria-live="polite"></p>
            </div>
            <p class="mt-4 text-sm text-slate-600">Type the translation for each word. Correct answers move to your correct list, and incorrect ones go to the incorrect list for review.</p>
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
    const practiceFeedback = document.getElementById('practice-feedback');

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
    let feedbackTimeout;

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
        if (!practiceFeedback) {
            return;
        }

        practiceFeedback.textContent = message;
        practiceFeedback.classList.remove('hidden', 'text-slate-600', 'text-red-500', 'text-emerald-600');

        const colorClass = type === 'success'
            ? 'text-emerald-600'
            : (type === 'error' ? 'text-red-500' : 'text-slate-600');

        practiceFeedback.classList.add(colorClass);

        clearTimeout(feedbackTimeout);
        feedbackTimeout = setTimeout(() => {
            practiceFeedback.classList.add('hidden');
        }, 4000);
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

            tooltip.textContent = tooltipValue || 'Translation unavailable';

            if (languageMode === LANGUAGE_ES_TO_EN && entry.other_common_meanings) {
                const extra = document.createElement('div');
                extra.className = 'mt-2 text-xs text-slate-200';
                extra.textContent = entry.other_common_meanings;
                tooltip.appendChild(extra);
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
     * Submits and evaluates a practice answer.
     *
     * @param {object} entry Vocabulary entry being practiced.
     * @param {HTMLInputElement} inputEl Text input element.
     * @param {HTMLButtonElement} submitButton Submit button element.
     * @returns {Promise<void>}
     */
    async function handlePracticeSubmission(entry, inputEl, submitButton) {
        const guess = inputEl.value.trim();
        if (guess === '') {
            inputEl.focus();
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = 'Checking…';

        try {
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

            const promptWord = languageMode === LANGUAGE_EN_TO_ES
                ? (entry.english || entry.spanish)
                : entry.spanish;

            if (result.result === 'correct') {
                removeEntryFromArray(practiceState.pending, entry.key);
                removeEntryFromArray(practiceState.incorrect, entry.key);
                addEntryIfMissing(practiceState.correct, entry);
                showPracticeFeedback('success', `${promptWord}: Correct!`);
            } else if (result.result === 'incorrect') {
                removeEntryFromArray(practiceState.pending, entry.key);
                addEntryIfMissing(practiceState.incorrect, entry);
                const answer = result.correctAnswer ? ` Correct answer: ${result.correctAnswer}.` : '';
                showPracticeFeedback('error', `${promptWord}: Not quite.${answer}`);
            }

            inputEl.value = '';
            renderPracticeList();
        } catch (error) {
            console.error('Unable to evaluate answer:', error);
            submitButton.disabled = false;
            submitButton.textContent = 'Submit';
            showPracticeFeedback('error', 'Unable to check that answer. Please try again.');
            return;
        }

        submitButton.disabled = false;
        submitButton.textContent = 'Submit';
    }

    /**
     * Produces the practice list contents for the active view.
     *
     * @returns {void}
     */
    function renderPracticeList() {
        updatePracticeCounts();
        practiceListContainer.innerHTML = '';

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
            const item = document.createElement('li');
            item.className = 'relative flex flex-col justify-between gap-4 rounded-lg border border-slate-200 bg-white px-5 py-5 shadow-sm transition-shadow hover:shadow-md';

            const prompt = document.createElement('span');
            prompt.className = 'text-lg font-semibold text-slate-900';
            prompt.textContent = languageMode === LANGUAGE_EN_TO_ES
                ? (entry.english || 'Translation unavailable')
                : entry.spanish;

            item.appendChild(prompt);

            if (activePracticeView === 'correct') {
                const answer = document.createElement('p');
                answer.className = 'text-sm text-slate-600';
                answer.innerHTML = languageMode === LANGUAGE_EN_TO_ES
                    ? `<span class="font-semibold text-emerald-600">${entry.spanish}</span>`
                    : `<span class="font-semibold text-emerald-600">${entry.english || 'Translation unavailable'}</span>`;
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
            input.className = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm text-slate-800 placeholder:text-slate-400 focus:border-slate-500 focus:outline-none focus:ring-2 focus:ring-slate-300';

            const submit = document.createElement('button');
            submit.type = 'submit';
            submit.className = 'inline-flex items-center justify-center rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-500';
            submit.textContent = 'Submit';

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                handlePracticeSubmission(entry, input, submit);
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

    renderTooltipList();
    renderPracticeList();
</script>
</body>
</html>
