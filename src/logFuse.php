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
 * - Automatic grouping of multi-line log entries (stack traces, exceptions)
 * - Extracts datetime, log level, message, file name, line number, and stack trace
 * - Supports pagination, sorting (asc/desc), and limiting the number of entries
 * - Output formats: HTML (with multiple themes) or JSON
 * - Fluent interface for chaining method calls (all methods return $this)
 * - Debug mode to log parsing steps
 * - Custom pattern injection
 * - Multi-language date formatting (en, de, tr)
 * - Unified access to tabular data sources (CSV, SQLite, MySQL, arrays) via addTabularSource()
 * - LAZY LOADING: all sources are read only when getOutput() is called
 * - SECURE database access: uses LIMIT/OFFSET from pagination, no unsafe SQL fragments
 * - CONFIGURABLE TIMEZONE for parsing date strings without timezone information
 *
 * ERROR HANDLING
 * --------------
 * By default, errors are collected internally and can be retrieved via getErrors().
 * Set 'throwExceptions' => true in constructor to throw InvalidArgumentException instead.
 * Fluent methods always return $this, even on error.
 *
 * USAGE EXAMPLE
 * -------------
 * $log = new logFuse(['debug' => false, 'timezone' => 'UTC']);
 * $log->addFile('/path/to/error.log');
 * $log->addTabularSource('sqlite:/var/log/guard.sqlite:rate_limit', null);
 * $log->setPagination(1, 50);
 * $log->setOrder('desc');
 *
 * if ($log->getErrors()) {
 *     echo "Errors: " . implode(', ', $log->getErrors());
 * } else {
 *     echo $log->getOutput('html');
 * }
 *
 * ============================================================
 */

class logFuse
{

    ########################### PROPERTIES

    private array $rawLines         = [];
    private array $entries          = [];
    private array $parsedEntries    = [];
    private string $language        = 'en';
    private array $parseCache       = [];
    private ?int $maxEntries        = null;
    private string $order           = 'desc';
    private int $pageNumber         = 1;
    private int $pageSize           = 0;
    private bool $dirty             = true;
    private bool $debug             = false;
    private array $errors           = [];
    private array $debugLog         = [];
    private array $userPatterns     = [];
    private array $sourceLoaders    = [];
    private string $defaultTimezone = 'UTC';
    private bool $throwExceptions   = false;



    ########################### CONSTRUCTOR & OPTIONS


    ##### CONSTRUCTOR: INITIALIZES THE PARSER WITH OPTIONAL SETTINGS.

    public function __construct(array $options = [])
    {
        $this->debug           = $options['debug']           ?? false;
        $this->throwExceptions = $options['throwExceptions'] ?? false;

        if (isset($options['timezone'])) {
            $this->defaultTimezone = $options['timezone'];
        }

        //-- Validate unknown options
        $allowed = ['debug', 'timezone', 'throwExceptions'];
        foreach ($options as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                $this->handleError("Unknown option '$key'");
            }
        }
    }



    ########################### ERROR HANDLING


    ##### HANDLES ERRORS: THROWS EXCEPTION OR COLLECTS ERROR MESSAGE.
    //  ALWAYS RETURNS $this TO MAINTAIN FLUENT INTERFACE

    private function handleError(string $message): self
    {
        if ($this->throwExceptions) {
            throw new \InvalidArgumentException($message);
        }
        $this->errors[] = $message;
        return $this;
    }


    ##### RETURNS COLLECTED ERRORS.

    public function getErrors(): array
    {
        return $this->errors;
    }



    ########################### PUBLIC API (FLUENT INTERFACE)


    ##### ADDS A LOG FILE BY PATH (LAZY – READ ON REBUILD).

    public function addFile(string $filePath): self
    {
        if (!is_readable($filePath)) {
            return $this->handleError('Log file not readable: ' . $filePath);
        }
        $this->sourceLoaders[] = function () use ($filePath) {
            return $this->readFileLinesWithLimit($filePath);
        };
        $this->dirty = true;
        return $this;
    }


    ##### ADDS RAW LOG CONTENT (LAZY).

    public function addFileContent(string $content): self
    {
        $this->sourceLoaders[] = function () use ($content) {
            $lines = explode("\n", $this->normalizeLineEndings($content));
            $limit = $this->getEffectiveRowLimit();
            return $limit ? array_slice($lines, 0, $limit) : $lines;
        };
        $this->dirty = true;
        return $this;
    }


    ##### SETS PAGINATION PARAMETERS (1‑BASED PAGE NUMBER, PAGE SIZE).

    public function setPagination(int $pageNumber, int $pageSize): self
    {
        if ($pageNumber < 1) {
            return $this->handleError('Page number must be >= 1, got ' . $pageNumber);
        }
        if ($pageSize < 0) {
            return $this->handleError('Page size must be >= 0, got ' . $pageSize);
        }
        $this->pageNumber = $pageNumber;
        $this->pageSize   = $pageSize;
        $this->dirty      = true;
        return $this;
    }


    ##### SETS THE ORDER OF ENTRIES ('ASC' OR 'DESC').

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


    ##### SETS MAXIMUM NUMBER OF ENTRIES TO KEEP (NULL = NO LIMIT).

    public function setMaxEntries(?int $maxEntries): self
    {
        if ($maxEntries !== null && $maxEntries < 1) {
            return $this->handleError('Max entries must be >= 1 or null, got ' . $maxEntries);
        }
        $this->maxEntries = $maxEntries;
        $this->dirty      = true;
        return $this;
    }


    ##### SETS LANGUAGE FOR DATE FORMATTING (EN, DE, TR).

    public function setLanguage(string $lang): self
    {
        if (in_array($lang, ['en', 'de', 'tr'], true)) {
            $this->language = $lang;
        }
        return $this;
    }


    ##### SETS DEFAULT TIMEZONE FOR PARSING DATE STRINGS.

    public function setDefaultTimezone(string $tz): self
    {
        $this->defaultTimezone = $tz;
        return $this;
    }


    ##### RETURNS TOTAL NUMBER OF ENTRIES AFTER GROUPING (IGNORES PAGINATION).

    public function getTotalEntryCount(): int
    {
        $this->rebuildIfDirty();
        return count($this->entries);
    }


    ##### RETURNS THE RAW ENTRY TEXTS (AFTER GROUPING AND PAGINATION).

    public function getEntries(): array
    {
        $this->rebuildIfDirty();
        return $this->entries;
    }


    ##### RETURNS STRUCTURED PARSED DATA FOR EACH ENTRY (DATETIME, LEVEL, MESSAGE, ETC.).

    public function getRawData(): array
    {
        $this->rebuildIfDirty();
        return $this->parsedEntries;
    }


    ##### RENDERS OUTPUT IN 'HTML' OR 'JSON' FORMAT.

    public function getOutput(string $format): string|false
    {
        $this->rebuildIfDirty();

        if (!empty($this->errors)) {
            return false;
        }

        $output = match ($format) {
            'html' => '<ul class="lf-list">' . $this->renderAll() . '</ul>',
            'json' => json_encode($this->parsedEntries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            default => $this->handleError('Unsupported format: ' . $format),
        };

        if ($output === false) {
            return false;
        }

        //-- Append debug information if debug mode is enabled
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


    ##### RETURNS CSS STYLES FOR HTML OUTPUT (SUPPORTS MULTIPLE THEMES).

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


    ##### UNIFIED ACCESS TO TABULAR DATA SOURCES (CSV, SQLITE, MYSQL, ARRAYS).

    public function addTabularSource(string $source, $mapping = null, array $options = []): self
    {
        //-- CSV file (detected by .csv extension)
        if (preg_match('/\.csv$/i', $source) && is_file($source)) {
            return $this->addCsvSource($source, $mapping, $options);
        }

        //-- Database source (format: "dsn:table")
        $colonPos = strrpos($source, ':');
        if ($colonPos !== false) {
            $dsn   = substr($source, 0, $colonPos);
            $table = substr($source, $colonPos + 1);
            if (!empty($dsn) && !empty($table)) {
                $this->addDatabaseSourceLazy($dsn, $table, $mapping, $options);
                return $this;
            }
        }

        return $this->handleError('Invalid source format. Expected CSV file path (.csv) or "dsn:table" for databases.');
    }


    ##### ADDS TABULAR DATA (ARRAY OR ITERATOR) AS LOG SOURCE (LAZY).

    public function addTabularData(iterable $rows, callable $rowFormatter): self
    {
        $this->sourceLoaders[] = function () use ($rows, $rowFormatter) {
            $lines = [];
            $limit = $this->getEffectiveRowLimit();
            $count = 0;
            foreach ($rows as $row) {
                if ($limit !== null && $count >= $limit) break;
                $lines[] = $this->normalizeLineEndings($rowFormatter($row));
                $count++;
            }
            return $lines;
        };
        $this->dirty = true;
        return $this;
    }



    ########################### DEBUG METHODS


    ##### ADDS A DEBUG MESSAGE TO THE INTERNAL LOG (ONLY IF DEBUG MODE IS ENABLED).

    public function addDebug(string $message): self
    {
        if ($this->debug) {
            $this->debugLog[] = $message;
        }
        return $this;
    }


    ##### OUTPUTS THE COLLECTED DEBUG LOG (EITHER TO ERROR_LOG OR AS HTML).

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


    ##### ADDS A CUSTOM REGEX PATTERN THAT IS TESTED BEFORE BUILT‑IN PATTERNS.

    public function addPattern(string $name, string $regex): self
    {
        $this->userPatterns[$name] = $regex;
        $this->dirty = true;
        return $this;
    }



    ########################### INTERNAL RENDERING


    ##### RENDERS ALL PARSED ENTRIES INTO HTML LIST ITEMS.

    private function renderAll(): string
    {
        $html = '';
        foreach ($this->parsedEntries as $parsed) {
            $html .= $this->renderParsedEntry($parsed);
        }
        return $html;
    }


    ##### RENDERS A SINGLE PARSED ENTRY AS HTML.

    private function renderParsedEntry(array $parsed): string
    {
        $datetime   = $parsed['datetime'];
        $level      = $parsed['level'];
        $message    = $parsed['message'];
        $file       = $parsed['file'];
        $lineNo     = $parsed['line'];
        $stacktrace = $parsed['stacktrace'];

        //-- Determine CSS class based on log level
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



    ########################### REBUILD LOGIC (LAZY)


    ##### REBUILDS ENTRIES AND PARSED DATA ONLY IF THE DIRTY FLAG IS SET.

    private function rebuildIfDirty(): void
    {
        if (!$this->dirty) {
            return;
        }
        $this->rebuild();
        $this->dirty = false;
    }


    ##### MAIN REBUILD: LOADS ALL LAZY SOURCES, GROUPS, APPLIES SORTING/LIMITS/PAGINATION, THEN PARSES.

    private function rebuild(): void
    {
        //-- 1) Load all pending source loaders into rawLines
        $allRawLines = [];
        foreach ($this->sourceLoaders as $loader) {
            $lines = $loader();
            foreach ($lines as $line) {
                $allRawLines[] = $line;
            }
        }
        $this->rawLines = $allRawLines;
        $this->sourceLoaders = [];

        //-- 2) Group raw lines into multi-line entries
        $allEntries = $this->groupRawLines($this->rawLines);

        //-- 3) Apply sorting (descending = reverse order)
        if ($this->order === 'desc') {
            $allEntries = array_reverse($allEntries);
        }

        //-- 4) Apply max entries limit
        if ($this->maxEntries !== null && $this->maxEntries > 0) {
            $allEntries = array_slice($allEntries, 0, $this->maxEntries);
        }

        //-- 5) Apply pagination
        $total = count($allEntries);
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

        //-- 6) Parse each entry
        $this->parsedEntries = [];
        foreach ($this->entries as $entry) {
            $this->parsedEntries[] = $this->analyze($entry);
        }
    }


    ##### READS A FILE LINE BY LINE AND RESPECTS THE EFFECTIVE ROW LIMIT.

    private function readFileLinesWithLimit(string $filePath): array
    {
        $limit = $this->getEffectiveRowLimit();
        $lines = [];
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return [];
        }

        $count = 0;
        while (($line = fgets($handle)) !== false) {
            if ($limit !== null && $count >= $limit) {
                break;
            }
            $lines[] = $this->normalizeLineEndings($line);
            $count++;
        }
        fclose($handle);
        return $lines;
    }


    ##### DETERMINES THE EFFECTIVE ROW LIMIT FOR RAW SOURCES.

    private function getEffectiveRowLimit(): ?int
    {
        //-- If pagination is active, use pageSize as limit
        if ($this->pageSize > 0) {
            return $this->pageSize;
        }
        //-- If maxEntries is set, estimate 5 lines per entry (conservative)
        if ($this->maxEntries !== null) {
            return $this->maxEntries * 5;
        }
        //-- Default: 10,000 rows
        return 10000;
    }


    ##### GROUPS RAW LINES INTO MULTI-LINE LOG ENTRIES BASED ON LINE-START PATTERNS.

    private function groupRawLines(array $rawLines): array
    {
        $entries = [];
        $current = '';

        $builtinPatterns = [
            '/^\[[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2}\.\d+ \d{4}\]/',
            '/^\[[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2} \d{4}\]/',
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/',
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',
            '/^\[[0-9]{2}-[A-Za-z]{3}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} [A-Za-z\/_]+\]/',
            '/^[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2}\.\d+ \d{4}/',
            '/^[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2} \d{4}/',
        ];

        $allPatterns = array_merge($builtinPatterns, array_values($this->userPatterns));

        foreach ($rawLines as $line) {
            $line = $this->normalizeLineEndings($line);
            $isNew = false;
            foreach ($allPatterns as $pattern) {
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


    ##### ANALYZES A SINGLE LOG ENTRY: EXTRACTS DATETIME, LEVEL, MESSAGE, STACKTRACE.

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

        //-- 1) Try user patterns then built-in patterns
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


    ##### ATTEMPTS TO MATCH THE ENTRY AGAINST ALL REGISTERED REGEX PATTERNS.

    private function tryFullPatterns(string $firstLine, string $rest, string $originalFirst): ?array
    {
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


    ##### FALLBACK PARSER: EXTRACTS DATETIME, LEVEL, MESSAGE SEQUENTIALLY.

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


    ##### EXTRACTS DATETIME FROM A LINE USING MULTIPLE PATTERN ATTEMPTS.

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


    ##### REMOVES THE EXTRACTED DATETIME SUBSTRING FROM THE LINE.

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


    ##### EXTRACTS LOG LEVEL FROM A LINE (E.G., "PHP ERROR:", "[WARNING]", "ERROR:").

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


    ##### PARSES STACKTRACE FROM THE REST OF THE ENTRY (AFTER THE FIRST LINE).

    private function parseStacktrace(string $rest, string &$message): array
    {
        $stacktrace = [];
        if ($rest === '') {
            return $stacktrace;
        }

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


    ##### FORMATS A DATETIME STRING OR TIMESTAMP INTO A LOCALIZED HUMAN-READABLE STRING.

    public function formatDate(string|int|false|null $timeElem = false): string
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

        //-- Convert input to timestamp (use configured timezone for strings without timezone)
        $timestamp = false;
        if ($timeElem === false || $timeElem === null || $timeElem === '') {
            $timestamp = time();
        } elseif (is_numeric($timeElem)) {
            $timestamp = (int)$timeElem;
        } elseif (is_string($timeElem)) {
            // Remove trailing timezone names like "CEST", "UTC", etc.
            $timeElem = preg_replace('/\s+[A-Za-z\/]+$/', '', $timeElem);

            // Use the configured default timezone for parsing
            $tz = new \DateTimeZone($this->defaultTimezone);

            if (preg_match('/^[0-9]{2}-[A-Za-z]{3}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $timeElem)) {
                $date = \DateTime::createFromFormat('d-M-Y H:i:s', $timeElem, $tz);
                $timestamp = $date ? $date->getTimestamp() : false;
            } elseif (preg_match('/^[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2}\.\d+ \d{4}$/', $timeElem)) {
                $date = \DateTime::createFromFormat('D M d H:i:s.u Y', $timeElem, $tz);
                $timestamp = $date ? $date->getTimestamp() : false;
            } elseif (preg_match('/^[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2} \d{4}$/', $timeElem)) {
                $date = \DateTime::createFromFormat('D M d H:i:s Y', $timeElem, $tz);
                $timestamp = $date ? $date->getTimestamp() : false;
            } else {
                // Fallback: strtotime uses server default timezone, but we assume UTC for consistency
                $oldTz = date_default_timezone_get();
                date_default_timezone_set($this->defaultTimezone);
                $timestamp = strtotime($timeElem);
                date_default_timezone_set($oldTz);
            }
        }
        if (!$timestamp || $timestamp <= 0) {
            return 'n/a';
        }

        //-- Relative day detection (using server's local time for display)
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



    ########################### TABULAR SOURCE HELPERS (LAZY DB/CSV)


    ##### ADDS A CSV FILE AS LOG SOURCE (LAZY).

    private function addCsvSource(string $file, $mapping, array $options): self
    {
        if (!is_readable($file)) {
            return $this->handleError("CSV file not readable: $file");
        }

        $delimiter = $options['csv_delimiter'] ?? ',';
        $hasHeader = $options['csv_header'] ?? false;

        $fileObj = new \SplFileObject($file);
        $fileObj->setFlags(\SplFileObject::READ_CSV);
        $fileObj->setCsvControl($delimiter);

        //-- Skip header if present
        if ($hasHeader) {
            $fileObj->current();
            $fileObj->next();
        }

        $formatter = $this->buildRowFormatter($mapping, $options);
        return $this->addTabularData($fileObj, $formatter);
    }


    ##### STORES A DATABASE SOURCE FOR LAZY LOADING.

    private function addDatabaseSourceLazy(string $dsn, string $table, $mapping, array $options): void
    {
        $this->sourceLoaders[] = function () use ($dsn, $table, $mapping, $options) {
            return $this->loadDatabaseRows($dsn, $table, $mapping, $options);
        };
        $this->dirty = true;
    }


    ##### ACTUALLY LOADS ROWS FROM DATABASE USING CURRENT PAGINATION SETTINGS (LIMIT/OFFSET).

    private function loadDatabaseRows(string $dsn, string $table, $mapping, array $options): array
    {
        $db = $this->connectDatabaseWithRetry($dsn, $options);
        if ($db === null) {
            return [];
        }

        //-- Use current pagination values
        $limit = $this->pageSize;
        if ($limit == 0) {
            $effective = $this->getEffectiveRowLimit();
            if ($effective !== null) {
                $limit = $effective;
            }
        }
        $offset = ($this->pageNumber - 1) * $this->pageSize;

        $sql = "SELECT * FROM " . $this->escapeIdentifier($table);
        if ($limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
            if ($offset > 0) {
                $sql .= " OFFSET " . (int)$offset;
            }
        }

        if ($this->debug) {
            $this->addDebug("DB Query: $sql");
        }

        $rows = [];
        if ($db instanceof \SQLite3) {
            $result = $db->query($sql);
            if ($result !== false) {
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $rows[] = $row;
                }
            }
        } elseif ($db instanceof \PDO) {
            $stmt = $db->query($sql);
            if ($stmt !== false) {
                $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            }
        }

        $formatter = $this->buildRowFormatter($mapping, $options);
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = $this->normalizeLineEndings($formatter($row));
        }
        return $lines;
    }


    ##### ESTABLISHES DATABASE CONNECTION FOR SQLITE OR PDO WITH RETRY.

    private function connectDatabaseWithRetry(string $dsn, array $options)
    {
        $maxAttempts = 2;
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                if (str_starts_with($dsn, 'sqlite:')) {
                    $dbFile = substr($dsn, 7);
                    $dbDir = dirname($dbFile);
                    if (!is_dir($dbDir)) {
                        mkdir($dbDir, 0755, true);
                    }
                    return new \SQLite3($dbFile);
                }

                $username = $options['username'] ?? '';
                $password = $options['password'] ?? '';
                $driverOptions = $options['driver_options'] ?? [];
                return new \PDO($dsn, $username, $password, $driverOptions);
            } catch (\Exception $e) {
                $lastException = $e;
                if ($this->debug) {
                    $this->addDebug("Database connection attempt $attempt failed: " . $e->getMessage());
                }
                if ($attempt >= $maxAttempts) {
                    return $this->handleDatabaseError($e);
                }
                usleep(100000);
            }
        }

        return null;
    }


    ##### HANDLES DATABASE CONNECTION ERRORS.

    private function handleDatabaseError(\Exception $e)
    {
        $message = "Database connection failed: " . $e->getMessage();
        if ($this->throwExceptions) {
            throw new \RuntimeException($message, 0, $e);
        }
        $this->errors[] = $message;
        return null;
    }


    ##### BUILDS A ROW FORMATTER CALLABLE BASED ON MAPPING CONFIGURATION.

    private function buildRowFormatter($mapping, array $options): callable
    {
        if (is_callable($mapping)) {
            return $mapping;
        }

        if ($mapping === null) {
            return function ($row) {
                $datetime = $row['datetime'] ?? $row['timestamp'] ?? $row['created_at'] ?? '';
                $level    = $row['level'] ?? $row['severity'] ?? 'INFO';
                $message  = $row['message'] ?? $row['msg'] ?? (is_array($row) ? json_encode($row) : (string)$row);
                return "[$datetime] $level: $message";
            };
        }

        if (isset($mapping[0]) && isset($mapping[1]) && isset($mapping[2])) {
            return function ($row) use ($mapping) {
                $datetime = $row[$mapping[0]] ?? '';
                $level    = $row[$mapping[1]] ?? 'INFO';
                $message  = $row[$mapping[2]] ?? '';
                return "[$datetime] $level: $message";
            };
        }

        if (isset($mapping['datetime']) || isset($mapping['level']) || isset($mapping['message'])) {
            return function ($row) use ($mapping) {
                $datetime = isset($mapping['datetime']) ? ($row[$mapping['datetime']] ?? '') : '';
                $level    = isset($mapping['level']) ? ($row[$mapping['level']] ?? 'INFO') : 'INFO';
                $message  = $this->buildMessageFromMapping($row, $mapping['message'] ?? '');
                return "[$datetime] $level: $message";
            };
        }

        return function ($row) {
            return is_array($row) ? implode(' | ', $row) : (string)$row;
        };
    }


    ##### BUILDS A MESSAGE STRING FROM ROW DATA USING THE MAPPING DEFINITION.

    private function buildMessageFromMapping(array $row, $messageDef): string
    {
        if (is_string($messageDef) && isset($row[$messageDef])) {
            return (string)$row[$messageDef];
        }

        if (is_string($messageDef) && strpos($messageDef, '{') !== false) {
            return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', function ($matches) use ($row) {
                return $row[$matches[1]] ?? '';
            }, $messageDef);
        }

        if (is_array($messageDef) && isset($messageDef['fields'])) {
            $parts = [];
            foreach ($messageDef['fields'] as $field) {
                $parts[] = $row[$field] ?? '';
            }
            $glue = $messageDef['glue'] ?? ' ';
            $prefix = $messageDef['prefix'] ?? '';
            $suffix = $messageDef['suffix'] ?? '';
            return $prefix . implode($glue, $parts) . $suffix;
        }

        return '';
    }


    ##### SECURELY ESCAPES A DATABASE IDENTIFIER (TABLE OR COLUMN NAME).

    private function escapeIdentifier(string $name): string
    {
        if (preg_match('/[^a-zA-Z0-9_\.]/', $name)) {
            throw new \InvalidArgumentException('Invalid identifier: ' . $name);
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }


    ##### NORMALIZES LINE ENDINGS TO UNIX FORMAT (\N).

    private function normalizeLineEndings(string $line): string
    {
        return rtrim(str_replace(["\r\n", "\r"], "\n", $line));
    }
}