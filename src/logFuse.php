<?php

declare(strict_types=1);

namespace e2;

/**
 * logFuse – Log File Parser, Grouper, and HTML/JSON Formatter
 * ============================================================
 *
 * This class parses log files using a hybrid approach: first, it attempts to match
 * user-defined regular expressions, then built-in patterns for common log formats
 * (Apache, PHP, MySQL, custom bracket formats). If no pattern matches, it falls back
 * to a modular extraction of datetime, log level, and message.
 *
 * FEATURES
 * --------
 * - Automatic grouping of multi‑line log entries (stack traces, exceptions)
 * - Extracts datetime, log level, message, file name, line number, and stack trace
 * - Supports pagination, sorting (asc/desc), and limiting the number of entries
 * - Output formats: HTML (with multiple themes) or JSON
 * - Fluent interface for chaining method calls
 * - Debug mode to log parsing steps
 * - Custom pattern injection (regex must include named subpatterns)
 * - Multi‑language date formatting (en, de, tr)
 *
 * USAGE EXAMPLE
 * -------------
 * $log = new logFuse(['throwExceptions' => false, 'debug' => true]);
 * $log->addFile('/path/to/error.log')
 *     ->setPagination(1, 50)
 *     ->setOrder('desc')
 *     ->setMaxEntries(1000)
 *     ->setLanguage('en');
 *
 * $total = $log->getTotalEntryCount();
 * $entries = $log->getEntries();          // raw entries after pagination
 * $parsed = $log->getRawData();            // structured array of parsed entries
 *
 * echo $log->getOutput('html');            // rendered HTML list
 * echo $log->getOutput('json');            // JSON representation
 *
 * echo logFuse::getCss('dark');            // CSS for HTML output
 *
 * CUSTOM PATTERN
 * --------------
 * $log->addPattern('myapp', '/^\[(?P<datetime>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (?P<level>\w+) (?P<message>.*)$/');
 *
 * STACK TRACE PARSING
 * -------------------
 * Lines following a stack trace header are parsed into numbered entries with file,
 * line number, and call information. The parsed stack trace is included in the output.
 *
 * ============================================================
 */

class logFuse
{
    // ------------------------------------------------------------------
    // PROPERTIES
    // ------------------------------------------------------------------
    private array $rawLines      = [];
    private array $entries       = [];
    private array $parsedEntries = [];
    private string $language     = 'en';
    private array $parseCache    = [];
    private ?int $maxEntries     = null;
    private string $order        = 'desc';
    private int $pageNumber      = 1;
    private int $pageSize        = 0;
    private bool $dirty          = true;
    private bool $throwExceptions;
    private bool $debug;
    private array $errors   = [];
    private array $debugLog = [];
    private array $userPatterns = [];   // benutzerdefinierte Patterns



    ########################### CONSTRUCTOR & OPTIONS



    ##### Constructor: Initializes the parser with optional settings.

    public function __construct(array $options = [])
    {
        //-- Store exception and debug flags
        $this->throwExceptions = $options['throwExceptions'] ?? true;
        $this->debug = $options['debug'] ?? false;
    }



    ########################### PUBLIC API (FLUENT INTERFACE)



    ##### Adds a log file by path, reads content and stores lines.

    public function addFile(string $filePath): self
    {
        //-- Check readability before reading
        if (!is_readable($filePath)) {
            return $this->handleError('Log file not readable: ' . $filePath);
        }
        $content = file_get_contents($filePath);
        if ($content === false) {
            return $this->handleError('Failed to read log file: ' . $filePath);
        }
        return $this->addFileContent($content);
    }



    ##### Adds raw log content (e.g., from a string) and splits into lines.

    public function addFileContent(string $content): self
    {
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            //-- Normalize line endings to Unix style
            $this->rawLines[] = $this->normalizeLineEndings($line);
        }
        $this->dirty = true;
        return $this;
    }



    ##### Sets pagination parameters (1‑based page number, page size).

    public function setPagination(int $pageNumber, int $pageSize): self
    {
        if ($pageNumber < 1) {
            return $this->handleError('Page number must be >= 1, got ' . $pageNumber);
        }
        if ($pageSize < 0) {
            return $this->handleError('Page size must be >= 0, got ' . $pageSize);
        }
        $this->pageNumber = $pageNumber;
        $this->pageSize = $pageSize;
        $this->dirty = true;
        return $this;
    }



    ##### Sets the order of entries ('asc' or 'desc').

    public function setOrder(string $order): self
    {
        $order = strtolower($order);
        if (!in_array($order, ['desc', 'asc'], true)) {
            return $this->handleError('Order must be \'desc\' or \'asc\', got ' . $order);
        }
        $this->order = $order;
        $this->dirty = true;
        return $this;
    }



    ##### Sets maximum number of entries to keep (null = no limit).

    public function setMaxEntries(?int $maxEntries): self
    {
        if ($maxEntries !== null && $maxEntries < 1) {
            return $this->handleError('Max entries must be >= 1 or null, got ' . $maxEntries);
        }
        $this->maxEntries = $maxEntries;
        $this->dirty = true;
        return $this;
    }



    ##### Sets language for date formatting (en, de, tr).

    public function setLanguage(string $lang): self
    {
        if (in_array($lang, ['en', 'de', 'tr'], true)) {
            $this->language = $lang;
        }
        return $this;
    }



    ##### Returns total number of entries after grouping (ignores pagination).

    public function getTotalEntryCount(): int
    {
        $this->rebuildIfDirty();
        return count($this->entries);
    }



    ##### Returns the raw entry texts (after grouping and pagination).

    public function getEntries(): array
    {
        $this->rebuildIfDirty();
        return $this->entries;
    }



    ##### Returns structured parsed data for each entry (datetime, level, message, etc.).

    public function getRawData(): array
    {
        $this->rebuildIfDirty();
        return $this->parsedEntries;
    }



    ##### Renders output in 'html' or 'json' format.

    public function getOutput(string $format): string
    {
        $this->rebuildIfDirty();

        //-- If errors occurred, show them instead of empty list
        if (!empty($this->errors)) {
            return $this->formatErrors($format);
        }

        $output = match ($format) {
            'html' => '<ul class="lf-list">' . $this->renderAll() . '</ul>',
            'json' => json_encode($this->parsedEntries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            default => throw new \InvalidArgumentException('Unsupported format: ' . $format),
        };

        //-- Append debug information if enabled
        if ($this->debug && !empty($this->debugLog)) {
            $debugStr = "DEBUG LOG:\n" . implode("\n", $this->debugLog);
            if ($format === 'html') {
                $output .= "\n<!--\n" . $debugStr . "\n-->";
            } elseif ($format === 'json') {
                $data = json_decode($output, true);
                $data['_debug'] = $this->debugLog;
                $output = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }

        return $output;
    }



    ##### Returns CSS styles for HTML output (supports multiple themes).

    public static function getCss(string $theme = 'peachy'): string
    {
        //-- Theme definitions (RGB values)
        $themes = [
            'light' => [
                'bg-base'               => '252,253,255',
                'bg-odd'                => '241,243,245',
                'datetime-color'        => '13,110,253',
                'file-color'            => '108,117,125',
                'file-file-color'       => '52,58,64',
                'header-border'         => '222,226,230',
                'level-error'           => '220,53,69',
                'level-info'            => '253,126,20',
                'level-unknown'         => '108,117,125',
                'message-bg'            => '255,255,255',
                'shadow-opacity'        => '0.1',
                'shadow'                => '0,0,0',
                'stacktrace-bg-opacity' => '0.5',
                'stacktrace-bg'         => '254,254,254',
            ],
            'dark' => [
                'bg-base'               => '29,29,29',
                'bg-odd'                => '38,38,38',
                'datetime-color'        => '110,168,254',
                'file-color'            => '170,170,170',
                'file-file-color'       => '221,221,221',
                'header-border'         => '85,85,85',
                'level-error'           => '248,113,113',
                'level-info'            => '251,191,36',
                'level-unknown'         => '156,163,175',
                'message-bg'            => '40,40,40',
                'shadow-opacity'        => '0.3',
                'shadow'                => '0,0,0',
                'stacktrace-bg-opacity' => '0.3',
                'stacktrace-bg'         => '42,42,42',
            ],
            'peachy' => [
                'bg-base'               => '255,245,240',
                'bg-odd'                => '255,232,224',
                'datetime-color'        => '230,126,34',
                'file-color'            => '201,126,90',
                'file-file-color'       => '180,95,43',
                'header-border-opacity' => '0.25',
                'header-border'         => '255,140,100',
                'level-error'           => '231,76,60',
                'level-info'            => '243,156,18',
                'level-unknown'         => '149,165,166',
                'message-bg-opacity'    => '0.8',
                'message-bg'            => '255,245,240',
                'shadow-opacity'        => '0.05',
                'shadow'                => '0,0,0',
                'stacktrace-bg'         => '255,255,255',
                'stacktrace-bg-opacity' => '.4',
            ],
            'e2' => [
                'bg-base-opacity'         => '1',
                'bg-base'                 => '0,43,55',
                'bg-odd-opacity'          => '0.15',
                'bg-odd'                  => '0,54,71',
                'datetime-color'          => '39,171,166',
                'file-color-opacity'      => '0.7',
                'file-color'              => '129,153,162',
                'file-file-color-opacity' => '0.9',
                'file-file-color'         => '255,255,255',
                'header-border-opacity'   => '0.5',
                'header-border'           => '39,171,166',
                'level-error'             => '226,89,80',
                'level-info'              => '255,159,72',
                'level-unknown'           => '153,153,153',
                'message-bg-opacity'      => '0.2',
                'message-bg'              => '0,0,0',
                'shadow-opacity'          => '0.3',
                'shadow'                  => '0,0,0',
                'stacktrace-bg-opacity'   => '0.3',
                'stacktrace-bg'           => '0,0,0',
            ],
        ];

        $themeData = $themes[$theme] ?? $themes['peachy'];

        //-- Build CSS custom properties
        $rootVars = ":root {\n";
        foreach ($themeData as $key => $value) {
            if (str_ends_with($key, '-opacity')) {
                $rootVars .= '    --lf-' . $key . ': ' . $value . ";\n";
            } else {
                $rootVars .= '    --lf-rgb-' . $key . ': ' . $value . ";\n";
            }
        }
        $rootVars .= "    --lf-border-radius: 8px;\n";
        $rootVars .= "    --lf-font-family: monospace, 'Segoe UI', 'Fira Code';\n";
        $rootVars .= "    --lf-stack-number-weight: bold;\n";
        $rootVars .= "}\n";

        $css = $rootVars . '
            ul.lf-list {
                list-style-type: none;
                margin: 0;
                padding: 0;
            }
            ul.lf-list > li {
                padding: 5px;
                margin: 0 0 10px;
                border-radius: var(--lf-border-radius);
                border: 1px solid rgba(255,255,255, .1);
                background: rgba(var(--lf-rgb-bg-base), 1);
                box-shadow: 2px 2px 6px rgba(var(--lf-rgb-shadow), var(--lf-shadow-opacity, 0.05));
                transition: transform 0.2s ease, box-shadow 0.2s ease;
            }
            ul.lf-list > li:hover {
                box-shadow: 2px 4px 10px rgba(var(--lf-rgb-shadow), calc(var(--lf-shadow-opacity, 0.05) * 2));
            }
            ul.lf-list > li:nth-child(odd) {
                background: rgba(var(--lf-rgb-bg-odd), 1);
            }
            .lf-entry {
                margin-bottom: 0;
                border-radius: var(--lf-border-radius);
                overflow: hidden;
                display: block;
            }
            .lf-header {
                display: flex;
                justify-content: space-between;
                align-items: baseline;
                gap: 16px;
                padding: 8px 12px 8px;
                font-family: var(--lf-font-family);
                font-size: 13px;
                font-weight: 600;
                border-bottom: 1px solid rgba(var(--lf-rgb-header-border), var(--lf-header-border-opacity, 1));
                background: rgba(var(--lf-rgb-bg-base), 0.5);
                border-radius: var(--lf-border-radius) var(--lf-border-radius) 0 0;
            }
            .lf-datetime {
                color: rgb(var(--lf-rgb-datetime-color));
                letter-spacing: 0.3px;
            }
            .lf-level {
                text-transform: uppercase;
                font-size: 11px;
                padding: 2px 8px;
                border-radius: 30px;
                background: rgba(var(--lf-rgb-bg-base), 0.3);
            }
            .lf-message {
                border-radius: 0 0 8px 8px;
                padding: 12px 14px;
                font-family: var(--lf-font-family);
                font-size: 13px;
                word-break: break-word;
                background: rgba(var(--lf-rgb-message-bg), var(--lf-message-bg-opacity, 1));
                line-height: 1.5;
                color: rgba(var(--lf-rgb-file-file-color), 0.9);
            }
            .lf-file {
                display: block;
                background: rgba(var(--lf-rgb-bg-odd), 0.6);
                padding: 1px 8px;
                border-radius: 10px;
                font-family: var(--lf-font-family);
                font-size: 11px;
                margin: 8px -8px 0;
                color: rgb(var(--lf-rgb-file-color));
            }
            .lf-file strong {
                font-weight: 600;
                color: rgb(var(--lf-rgb-file-file-color));
            }
            .lf-entry.error .lf-level {
                background: rgba(var(--lf-rgb-level-error), 0.15);
                color: rgb(var(--lf-rgb-level-error));
            }
            .lf-entry.info .lf-level {
                background: rgba(var(--lf-rgb-level-info), 0.15);
                color: rgb(var(--lf-rgb-level-info));
            }
            .lf-entry.unknown .lf-level {
                background: rgba(var(--lf-rgb-level-unknown), 0.15);
                color: rgb(var(--lf-rgb-level-unknown));
            }
            .lf-stacktrace {
                border-radius: 8px;
                font-family: var(--lf-font-family);
                font-size: 11px;
                margin: 5px 0 0 0;
                padding: 10px 14px;
                background: rgba(var(--lf-rgb-stacktrace-bg), var(--lf-stacktrace-bg-opacity));
                word-break: break-word;
                line-height: 1.1;
            }
            .lf-stack-line {
                opacity: calc(var(--lf-progress) / 100);
                color: rgb(var(--lf-rgb-file-color));
                margin-bottom: 4px;
                padding: 2px 0;
            }

            .lf-stack-number {
                font-weight: var(--lf-stack-number-weight);
                margin-right: 12px;
                color: rgb(var(--lf-rgb-datetime-color));
            }
            .lf-stack-header {
                font-weight: bold;
                color: rgb(var(--lf-rgb-file-file-color));
                margin-bottom: 6px;
                border-bottom: 1px dashed rgba(var(--lf-rgb-stacktrace-border), 0.3);
                padding-bottom: 4px;
            }
        ';

        return $css;
    }



    ########################### DEBUG METHODS



    ##### Adds a debug message to the internal log (only if debug mode is enabled).

    public function addDebug(string $message): self
    {
        if ($this->debug) {
            $this->debugLog[] = $message;
        }
        return $this;
    }



    ##### Outputs the collected debug log (either to error_log or as HTML).

    public function getDebug(string $mode = 'output'): void
    {
        if (empty($this->debugLog)) {
            return;
        }
        $output = "=== logFuse Debug Log ===\n" . implode("\n", $this->debugLog) . "\n===========================\n";
        if ($mode === 'log') {
            error_log($output);
        } else {
            echo '<pre>' . htmlspecialchars($output) . '</pre>';
        }
    }



    ########################### PATTERN MANAGEMENT



    ##### Adds a custom regex pattern that is tested before built‑in patterns.

    public function addPattern(string $name, string $regex): self
    {
        $this->userPatterns[$name] = $regex;
        $this->dirty = true;
        return $this;
    }



    ########################### INTERNAL RENDERING



    ##### Renders all parsed entries into HTML.

    private function renderAll(): string
    {
        $html = '';
        foreach ($this->parsedEntries as $parsed) {
            $html .= $this->renderParsedEntry($parsed);
        }
        return $html;
    }



    ##### Renders a single parsed entry as HTML <li> element.

    private function renderParsedEntry(array $parsed): string
    {
        $datetime   = $parsed['datetime'];
        $level      = $parsed['level'];
        $message    = $parsed['message'];
        $file       = $parsed['file'];
        $lineNo     = $parsed['line'];
        $stacktrace = $parsed['stacktrace'];

        //-- Determine CSS class based on severity
        $levelClass = match (strtolower($level)) {
            'fatal error', 'error', 'emerg' => 'error',
            default => 'info',
        };
        if ($level === 'raw') $levelClass = 'unknown';

        $html = '<li class="lf-entry ' . $levelClass . '">';
        $html .= '<div class="lf-header">';
        $html .= '<span class="lf-datetime">' . ((!empty($datetime)) ? htmlspecialchars($this->formatDate($datetime)) : '') . '</span>';
        $html .= '<span class="lf-level">' . htmlspecialchars($level) . '</span>';
        $html .= '</div>';
        $html .= '<div class="lf-message">' . nl2br(htmlspecialchars($message));

        //-- Show file information if available
        if ($file) {
            $dirname   = dirname($file);
            $basename  = basename($file);
            $separator = '/';
            if ($dirname === '.' || $dirname === '') {
                $fileHtml = '<strong>' . htmlspecialchars($basename) . '</strong>';
            } else {
                $fileHtml = htmlspecialchars($dirname) . $separator . '<strong>' . htmlspecialchars($basename) . '</strong>';
            }
            $html .= ' <span class="lf-file">in ' . $fileHtml . ':' . $lineNo . '</span>';
        }
        $html .= '</div>';

        //-- Render stack trace if present
        if (!empty($stacktrace)) {
            $html .= '<div class="lf-stacktrace">';
            $entries = array_filter($stacktrace, fn($item) => is_array($item) && ($item['type'] ?? '') === 'entry');
            $total = count($entries);
            $idx = 0;
            foreach ($stacktrace as $item) {
                if (is_array($item) && ($item['type'] ?? '') === 'header') {
                    $html .= '<div class="lf-stack-header">' . htmlspecialchars($item['text']) . '</div>';
                } elseif (is_array($item) && ($item['type'] ?? '') === 'entry') {
                    $idx++;
                    $progress = ($total > 0) ? round((($total - $idx + 1) / $total) * 100) : 100;
                    $html .= '<div class="lf-stack-line" style="--lf-progress: ' . $progress . ';">';
                    $html .= '<span class="lf-stack-number">#' . htmlspecialchars($item['number']) . '</span>';
                    if (!empty($item['file'])) {
                        $stackDirname  = dirname($item['file']);
                        $stackBasename = basename($item['file']);
                        $separator = '/';
                        if ($stackDirname === '.' || $stackDirname === '') {
                            $stackFileHtml = '<strong>' . htmlspecialchars($stackBasename) . '</strong>';
                        } else {
                            $stackFileHtml = htmlspecialchars($stackDirname) . $separator . '<strong>' . htmlspecialchars($stackBasename) . '</strong>';
                        }
                        $html .= ' <span class="lf-stack-file">in ' . $stackFileHtml;
                        if ($item['line'] > 0) {
                            $html .= ':' . $item['line'];
                        }
                        $html .= '</span>';
                    }
                    if (!empty($item['call'])) {
                        $html .= ' <span class="lf-stack-call">' . htmlspecialchars($item['call']) . '</span>';
                    }
                    $html .= '</div>';
                } elseif (is_string($item)) {
                    $html .= '<div class="lf-stack-line">' . htmlspecialchars($item) . '</div>';
                }
            }
            $html .= '</div>';
        }

        $html .= '</li>';
        return $html;
    }



    ########################### REBUILD LOGIC



    ##### Rebuilds entries and parsed data if the dirty flag is set.

    private function rebuildIfDirty(): void
    {
        if (!$this->dirty) {
            return;
        }
        $this->rebuild();
        $this->dirty = false;
    }



    ##### Performs the full rebuild: grouping, ordering, limiting, pagination, parsing.

    private function rebuild(): void
    {
        //-- Group raw lines into multi‑line entries
        $allEntries = $this->groupRawLines($this->rawLines);

        //-- Apply order
        if ($this->order === 'desc') {
            $allEntries = array_reverse($allEntries);
        }

        //-- Apply max entries limit
        if ($this->maxEntries !== null && $this->maxEntries > 0) {
            $allEntries = array_slice($allEntries, 0, $this->maxEntries);
        }

        $total = count($allEntries);
        //-- Apply pagination
        if ($this->pageSize > 0) {
            $offset = ($this->pageNumber - 1) * $this->pageSize;
            if ($offset >= $total) {
                $this->entries = [];
                $this->parsedEntries = [];
                return;
            }
            $this->entries = array_slice($allEntries, $offset, $this->pageSize);
        } else {
            $this->entries = $allEntries;
        }

        //-- Parse each entry
        $this->parsedEntries = [];
        foreach ($this->entries as $entry) {
            $this->parsedEntries[] = $this->analyze($entry);
        }
    }



    ##### Groups raw lines into logical entries based on datetime patterns.

    private function groupRawLines(array $rawLines): array
    {
        $entries = [];
        $current = '';

        //-- Patterns that indicate the start of a new log entry
        $datePatterns = [
            '/^\[[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2}\.\d+ \d{4}\]/',
            '/^\[[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2} \d{4}\]/',
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/',
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',
            '/^\[[0-9]{2}-[A-Za-z]{3}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} [A-Za-z\/_]+\]/',
            '/^[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2}\.\d+ \d{4}/',
            '/^[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2} \d{4}/',
        ];

        foreach ($rawLines as $line) {
            $line = $this->normalizeLineEndings($line);
            $isNew = false;
            foreach ($datePatterns as $pattern) {
                if (preg_match($pattern, $line)) {
                    $isNew = true;
                    break;
                }
            }
            if ($isNew) {
                if ($current !== '') {
                    $entries[] = $current;
                }
                $current = $line;
            } else {
                if ($current !== '') {
                    $current .= "\n" . $line;
                } else {
                    $current = $line;
                }
            }
        }
        if ($current !== '') {
            $entries[] = $current;
        }
        return $entries;
    }



    ########################### HYBRID PARSING



    ##### Main parsing method: tries patterns, then modular fallback.

    private function analyze(string $entry): array
    {
        if ($this->debug) {
            $this->addDebug('----- ANALYZE NEW ENTRY -----');
            $this->addDebug('RAW ENTRY (first 300 chars): ' . substr($entry, 0, 300));
        }

        //-- Return cached result if available
        if (array_key_exists($entry, $this->parseCache)) {
            if ($this->debug) $this->addDebug('CACHED RESULT');
            return $this->parseCache[$entry];
        }

        $lines         = explode("\n", $entry);
        $firstLine     = array_shift($lines);
        $rest          = implode("\n", $lines);
        $originalFirst = $firstLine;
        $firstLine     = rtrim($firstLine);

        if ($this->debug) {
            $this->addDebug('FIRST LINE: ' . $firstLine);
            $this->addDebug('REST LINES: ' . (empty($rest) ? '(empty)' : substr($rest, 0, 300)));
        }

        //-- 1) Try user patterns then built‑in patterns
        $result = $this->tryFullPatterns($firstLine, $rest, $originalFirst);
        if ($result !== null) {
            $this->parseCache[$entry] = $result;
            return $result;
        }

        //-- 2) Modular fallback extraction
        $result = $this->modularFallback($firstLine, $rest, $originalFirst);
        $this->parseCache[$entry] = $result;
        return $result;
    }



    ##### Attempts to match the first line against all registered patterns.

    private function tryFullPatterns(string $firstLine, string $rest, string $originalFirst): ?array
    {
        //-- Built‑in patterns with named subpatterns
        $builtinPatterns = [
            'apacheUs'            => '/^\[(?P<datetime>[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2}\.\d+ \d{4})\] \[(?P<module>\w+):(?P<level>\w+)\] (?P<message>.*)$/',
            'apache'              => '/^\[(?P<datetime>[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2} \d{4})\] \[(?P<module>\w+):(?P<level>\w+)\] (?P<message>.*)$/',
            'bracketModuleLevel'  => '/^\[(?P<datetime>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \[(?P<module>\w+):(?P<level>\w+)\] (?P<message>.*)$/',
            'phpTz'               => '/^\[(?P<datetime>[0-9]{2}-[A-Za-z]{3}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} [A-Za-z\/_]+)\] PHP (?P<level>[A-Za-z ]+?): (?P<message>.*)$/i',
            'mysql'               => '/^(?P<datetime>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \d+ \[(?P<level>\w+)\] (?P<message>.*)$/',
            'php'                 => '/^(?P<datetime>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) PHP (?P<level>[A-Za-z ]+?): (?P<message>.*)$/i',
            'custom'              => '/^(?P<datetime>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) \[(?P<level>\w+)\] (?P<message>.*)$/',
        ];

        $allPatterns = array_merge($this->userPatterns, $builtinPatterns);

        foreach ($allPatterns as $name => $regex) {
            if (preg_match($regex, $firstLine, $m)) {
                if ($this->debug) $this->addDebug('PATTERN MATCHED: ' . $name);
                $datetime = $m['datetime'] ?? '';
                $level    = $m['level'] ?? 'app';
                $message  = $m['message'] ?? '';

                //-- Special handling for bracketDate pattern
                if ($name === 'bracketDate' && isset($m['level'])) {
                    $level = $m['level'];
                }

                //-- Extract file and line number from message
                $file = '';
                $lineNo = 0;
                if (preg_match('/ in (?P<file>.+?)(?::| on line )(?P<line>\d+)$/i', $message, $fm)) {
                    $file = $fm['file'];
                    $lineNo = (int)$fm['line'];
                    $message = preg_replace('/ in .+?(?::| on line )\d+$/i', '', $message);
                }

                $stacktrace = $this->parseStacktrace($rest, $message);
                return [
                    'datetime'   => $datetime,
                    'level'      => $level,
                    'message'    => trim($message),
                    'file'       => $file,
                    'line'       => $lineNo,
                    'stacktrace' => $stacktrace,
                ];
            }
        }
        return null;
    }



    ##### Fallback parser that extracts datetime, level, message step by step.

    private function modularFallback(string $firstLine, string $rest, string $originalFirst): array
    {
        if ($this->debug) $this->addDebug('MODULAR FALLBACK');

        $datetime = $this->extractDatetimeFromLine($firstLine);
        $remaining = $firstLine;
        if ($datetime !== '') {
            $remaining = $this->removeDatetimeFromLine($firstLine, $datetime);
        }

        $level = 'app';
        $levelExtract = $this->extractLevelFromLine($remaining);
        if ($levelExtract !== null) {
            $level = $levelExtract['level'];
            $remaining = $levelExtract['remaining'];
        }

        $message = trim($remaining);
        if ($message === '' && $rest !== '') {
            $message = trim($rest);
        }

        //-- Extract file and line number
        $file = '';
        $lineNo = 0;
        if (preg_match('/ in (?P<file>.+?)(?::| on line )(?P<line>\d+)$/i', $message, $fm)) {
            $file = $fm['file'];
            $lineNo = (int)$fm['line'];
            $message = preg_replace('/ in .+?(?::| on line )\d+$/i', '', $message);
        }

        $stacktrace = $this->parseStacktrace($rest, $message);

        return [
            'datetime'   => $datetime,
            'level'      => $level,
            'message'    => trim($message),
            'file'       => $file,
            'line'       => $lineNo,
            'stacktrace' => $stacktrace,
        ];
    }



    ##### Extracts a datetime string from the beginning of a line.

    private function extractDatetimeFromLine(string $line): string
    {
        $datePatterns = [
            '/^\[(?P<datetime>[0-9]{2}-[A-Za-z]{3}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2}(?: [A-Za-z\/_]+)?)\]/',
            '/^\[(?P<datetime>[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)? \d{4})\]/',
            '/^(?P<datetime>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/',
            '/^\[(?P<datetime>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/',
            '/^(?P<datetime>[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)? \d{4})/',
        ];
        foreach ($datePatterns as $pattern) {
            if (preg_match($pattern, $line, $m)) {
                return $m['datetime'];
            }
        }
        return '';
    }



    ##### Removes a datetime string from the beginning of a line.

    private function removeDatetimeFromLine(string $line, string $datetime): string
    {
        if (str_starts_with($line, '[' . $datetime . ']')) {
            return trim(substr($line, strlen('[' . $datetime . ']')));
        }
        if (str_starts_with($line, $datetime)) {
            return trim(substr($line, strlen($datetime)));
        }
        return $line;
    }



    ##### Extracts log level from the remaining part of the line.

    private function extractLevelFromLine(string $line): ?array
    {
        $levelPatterns = [
            '/^PHP (?P<level>[A-Za-z ]+?):/i',
            '/^\[(?P<level>\w+)\]/',
            '/^(?P<level>[A-Z]+):/',
            '/^(?P<level>GUARD):/i',
            '/^(?P<level>BLOCKED):/i',
        ];
        foreach ($levelPatterns as $pattern) {
            if (preg_match($pattern, $line, $m)) {
                return [
                    'level'     => $m['level'],
                    'remaining' => trim(substr($line, strlen($m[0])))
                ];
            }
        }
        return null;
    }



    ########################### STACKTRACE PARSING



    ##### Parses a stack trace from the remaining lines after the first line.

    private function parseStacktrace(string $rest, string &$message): array
    {
        $stacktrace = [];
        if ($rest === '') {
            return $stacktrace;
        }

        //-- Identify stack trace block
        $stackBlock = '';
        if (preg_match('/^(Stack trace:.*)$/s', $rest, $sm)) {
            $stackBlock = $sm[1];
            if ($this->debug) $this->addDebug('Stack block found via \'Stack trace:\' pattern');
        } elseif (preg_match('/^(#\d+.*)$/m', $rest, $sm)) {
            $stackBlock = $sm[1];
            if ($this->debug) $this->addDebug('Stack block found via \'#0\' pattern');
        }

        if ($stackBlock !== '') {
            if ($this->debug) $this->addDebug('STACK BLOCK RAW (first 200): ' . substr($stackBlock, 0, 200));

            //-- Remove stack block from rest
            $rest = str_replace($stackBlock, '', $rest);
            $rest = ltrim($rest, "\n\r");

            $rawStack = explode("\n", $stackBlock);
            $inStack = false;
            foreach ($rawStack as $stackLine) {
                $stackLine = trim($stackLine);
                if ($stackLine === '') continue;
                if (str_starts_with($stackLine, 'Stack trace:')) {
                    $stacktrace[] = ['type' => 'header', 'text' => $stackLine];
                    $inStack = true;
                    continue;
                }
                if ($inStack && preg_match('/^#(\d+)\s+(\S+)(?:\((\d+)\))?:\s+(.*)$/', $stackLine, $m)) {
                    $stacktrace[] = [
                        'type'   => 'entry',
                        'number' => $m[1],
                        'file'   => $m[2],
                        'line'   => isset($m[3]) ? (int)$m[3] : 0,
                        'call'   => $m[4] ?? ''
                    ];
                    if ($this->debug) $this->addDebug('Stack entry #' . $m[1] . ': ' . $m[2] . ':' . ($m[3] ?? '?') . ' -> ' . ($m[4] ?? ''));
                } elseif ($inStack && preg_match('/^#(\d+)\s+(.*)$/', $stackLine, $m)) {
                    $stacktrace[] = [
                        'type'   => 'entry',
                        'number' => $m[1],
                        'file'   => '',
                        'line'   => 0,
                        'call'   => $m[2]
                    ];
                    if ($this->debug) $this->addDebug('Stack entry #' . $m[1] . ': ' . $m[2]);
                } elseif ($inStack && $stackLine === '{main}') {
                    $stacktrace[] = [
                        'type'   => 'entry',
                        'number' => 'main',
                        'file'   => '',
                        'line'   => 0,
                        'call'   => '{main}'
                    ];
                    if ($this->debug) $this->addDebug('Stack entry: {main}');
                } elseif ($inStack && preg_match('/^thrown in /', $stackLine)) {
                    $stacktrace[] = $stackLine;
                    if ($this->debug) $this->addDebug('Thrown line added as string: ' . $stackLine);
                } else {
                    if ($inStack) {
                        $stacktrace[] = $stackLine;
                        if ($this->debug) $this->addDebug('Unmatched stack line added as string: ' . $stackLine);
                    }
                }
            }
        }

        return $stacktrace;
    }



    ########################### DATE FORMATTING



    ##### Formats a datetime string or timestamp into a human‑readable string.

    private function formatDate(string|int|false|null $uts = false): string
    {
        $lang = $this->language;
        $months = match ($lang) {
            'de'    => [1 => 'Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'],
            'en'    => [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            'tr'    => [1 => 'Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'],
            default => [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        };
        $relative = match ($lang) {
            'de' => ['vorgestern', 'gestern', 'heute'],
            'en' => ['day before yesterday', 'yesterday', 'today'],
            'tr' => ['evvelsi gün', 'dün', 'bugün'],
        };

        //-- Convert input to timestamp
        $timestamp = false;
        if ($uts === false || $uts === null || $uts === '') {
            $timestamp = time();
        } elseif (is_numeric($uts)) {
            $timestamp = (int)$uts;
        } elseif (is_string($uts)) {
            $uts = preg_replace('/\s+[A-Za-z\/]+$/', '', $uts);
            if (preg_match('/^[0-9]{2}-[A-Za-z]{3}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $uts)) {
                $date = \DateTime::createFromFormat('d-M-Y H:i:s', $uts);
                $timestamp = $date ? $date->getTimestamp() : false;
            } elseif (preg_match('/^[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2}\.\d+ \d{4}$/', $uts)) {
                $date = \DateTime::createFromFormat('D M d H:i:s.u Y', $uts);
                $timestamp = $date ? $date->getTimestamp() : false;
            } elseif (preg_match('/^[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2} \d{4}$/', $uts)) {
                $date = \DateTime::createFromFormat('D M d H:i:s Y', $uts);
                $timestamp = $date ? $date->getTimestamp() : false;
            } else {
                $timestamp = strtotime($uts);
            }
        }

        if (!$timestamp || $timestamp <= 0) {
            return 'n/a';
        }

        //-- Relative day detection
        $day        = (int) date('j', $timestamp);
        $month      = (int) date('n', $timestamp);
        $year       = (int) date('Y', $timestamp);
        $todayBegin = strtotime(date('Y-m-d') . ' 00:00:00');
        $prev2Begin = $todayBegin - 86400 * 2;
        $prev1Begin = $todayBegin - 86400;

        if ($timestamp >= $prev2Begin && $timestamp < $todayBegin) {
            if ($timestamp < $prev1Begin) {
                return $relative[0] . ', ' . date('H:i:s', $timestamp);
            } else {
                return $relative[1] . ', ' . date('H:i:s', $timestamp);
            }
        }
        if ($timestamp >= $todayBegin && $timestamp < $todayBegin + 86400) {
            return $relative[2] . ', ' . date('H:i:s', $timestamp);
        }

        $yearStr = ($year == date('Y')) ? '' : ' ' . $year;
        return $day . '. ' . $months[$month] . $yearStr . ', ' . date('H:i:s', $timestamp);
    }



    ########################### ERROR HANDLING



    ##### Handles errors: either throws exception or stores message.

    private function handleError(string $message): self
    {
        if ($this->throwExceptions) {
            throw new \InvalidArgumentException($message);
        }
        $this->errors[] = $message;
        return $this;
    }



    ##### Formats error messages for HTML or JSON output.

    private function formatErrors(string $format): string
    {
        $errorHtml = '<div class="lf-errors" style="background:#f8d7da; border-left:4px solid #e25950; padding:12px; margin-bottom:16px; border-radius:4px; font-family:monospace;">'
            . '<strong>Log Error(s):</strong><br>'
            . implode('<br>', array_map('htmlspecialchars', $this->errors))
            . '</div>';

        if ($format === 'html') {
            return $errorHtml . '<ul class="lf-list"></ul>';
        }

        return json_encode(['errors' => $this->errors, 'entries' => []], JSON_PRETTY_PRINT);
    }



    ########################### HELPERS



    ##### Normalizes line endings to Unix style (\n).

    private function normalizeLineEndings(string $line): string
    {
        return rtrim(str_replace(["\r\n", "\r"], "\n", $line));
    }
}
