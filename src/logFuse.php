<?php

declare(strict_types=1);

namespace e2;

/**
 * logFuse – High‑performance log file parser with index‑based pagination and tabular data source support
 * =========================================================================================================
 *
 * logFuse reads log files (or tabular data like CSV, SQLite, MySQL) and provides a unified interface to
 * parse, paginate, sort, format, and render log entries. For plain text log files, it builds a persistent
 * byte‑offset index for perfect descending pagination without missing entries and without reading the whole
 * file repeatedly. For tabular sources (CSV, SQLite, MySQL, arrays) it works row‑based but still supports
 * pagination, ordering, and the same output formatting.
 *
 *
 * 1. INDEX‑BASED FILE MODE (RECOMMENDED FOR LOG FILES)
 * ----------------------------------------------------
 * - Automatically detects entry start lines (date/time patterns or user‑defined regex).
 * - Builds a binary index file (stored in index directory) mapping each entry to its byte offset.
 * - Index is regenerated only when the source file changes (size/mtime).
 * - Supports fast DESC pagination without scanning the entire file.
 * - Works with huge files (GB+).
 *
 * 2. TABULAR MODE (CSV, SQLite, MySQL, ARRAYS)
 * ---------------------------------------------
 * - Use addTabularSource() with a CSV file path or a DSN:table string (e.g. "sqlite:/path/to.db:logs").
 * - Optionally provide a mapping for datetime, level, message columns.
 * - For custom row formatting, use addTabularData() with a callable.
 * - Pagination is applied on the row level (LIMIT/OFFSET for databases, slicing for CSV/arrays).
 *
 *
 * FEATURES
 * --------
 * - Multi‑language date formatting (en, de, tr).
 * - Automatic extraction of datetime, log level, message, file path, line number.
 * - Stack trace detection and structured rendering (supports PHP, Apache, custom formats).
 * - Persistent index caching (very fast repeated requests).
 * - CSS theming (light, dark, peachy, e2) via getCss().
 * - Output formats: HTML (default) or JSON.
 * - Debug mode with detailed logging.
 *
 *
 * BASIC USAGE (FILE MODE)
 * -----------------------
 *   $log = new \e2\logFuse(['debug' => true]);
 *   $log->addFile('/var/log/php_errors.log')
 *       ->setIndexDirectory('/tmp/logfuse_cache')
 *       ->setPagination(1, 50)          // page 1, 50 entries per page
 *       ->setOrder('desc')              // newest first
 *       ->setLanguage('en')
 *       ->addPattern('my_app', '/^\[(?P<datetime>.*?)\] \[(?P<level>\w+)\] (?P<message>.*)/');
 *   echo $log->getOutput('html');
 *
 *
 * BASIC USAGE (TABULAR MODE – CSV)
 * --------------------------------
 *   $log = new \e2\logFuse();
 *   $log->addTabularSource('/path/to/access.csv', ['datetime', 'level', 'message'], ['csv_header' => true])
 *       ->setPagination(1, 30)
 *       ->setOrder('desc');
 *   echo $log->getOutput('html');
 *
 *
 * BASIC USAGE (TABULAR MODE – SQLite)
 * -----------------------------------
 *   $log->addTabularSource('sqlite:/tmp/app.db:error_log', ['created_at', 'severity', 'error_msg'])
 *       ->setPagination(1, 20);
 *
 *
 * ADVANCED: CUSTOM ROW FORMATTER (ARRAY DATA)
 * -------------------------------------------
 *   $rows = [
 *       ['dt' => '2025-01-01 12:00:00', 'lvl' => 'ERROR', 'msg' => 'Disk full'],
 *       ['dt' => '2025-01-01 12:01:00', 'lvl' => 'INFO',  'msg' => 'Recovery started'],
 *   ];
 *   $log->addTabularData($rows, function($row) {
 *       return "[" . $row['dt'] . "] " . $row['lvl'] . ": " . $row['msg'];
 *   });
 *
 *
 * INDEX DIRECTORY & CACHING
 * -------------------------
 * - If no index directory is set, logFuse uses sys_get_temp_dir() . '/logfuse_cache'.
 * - Index files are named by a hash of source identifiers + user patterns + timezone.
 * - To force index rebuild, call clearIndexCache() or delete the index file manually.
 *
 *
 * ERROR HANDLING
 * --------------
 * - By default, errors are collected in getErrors() and the class continues.
 * - Set 'throwExceptions' => true in constructor to throw exceptions on critical errors.
 *
 *
 * DEBUGGING
 * ---------
 * - Enable debug mode in constructor: new logFuse(['debug' => true]).
 * - Call getDebug('output') to see detailed logs (HTML comment or echo).
 * - Debug logs include index building steps, pattern matching, pagination decisions.
 *
 *
 * CSS THEMES
 * ----------
 * - Use logFuse::getCss('peachy') to embed styles. Built‑in themes: 'light', 'dark', 'peachy', 'e2'.
 * - Rendered HTML uses CSS custom properties (--lf-rgb-*) – easy to override.
 *
 * =========================================================================================================
 */

class logFuse
{
    // ---------- Common properties ----------
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
    private string $defaultTimezone = 'UTC';
    private bool $throwExceptions   = false;

    // ---------- Index‑based file handling ----------
    private ?string $indexedFile      = null;
    private array   $sourceIdentifiers = [];
    private string  $indexDirectory    = '';
    private array   $tempFiles         = [];

    // ---------- Tabular mode ----------
    private array $sourceLoaders = [];
    private bool $tabularMode    = false;



    ########################### CONSTRUCTOR & ERROR HANDLING

    ##### INITIALIZES THE LOG PARSER WITH OPTIONAL DEBUG AND EXCEPTION SETTINGS.

    public function __construct(array $options = [])
    {
        //-- Store configuration flags
        $this->debug           = $options['debug']           ?? false;
        $this->throwExceptions = $options['throwExceptions'] ?? false;
        $this->defaultTimezone = $options['timezone']        ?? 'UTC';

        $allowed = ['debug', 'timezone', 'throwExceptions'];
        foreach ($options as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                $this->handleError("Unknown option '$key'");
            }
        }
        $this->addDebug('logFuse constructed');
    }



    ##### CLEANS UP TEMPORARY FILES (E.G., FROM ADDFILECONTENT).

    public function __destruct()
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
                $this->addDebug("Deleted temp file: $file");
            }
        }
    }



    ##### INTERNAL ERROR HANDLER: LOGS, THROWS IF REQUESTED, OR STORES ERROR.

    private function handleError(string $message): self
    {
        $this->addDebug("ERROR: $message");
        if ($this->throwExceptions) {
            throw new \InvalidArgumentException($message);
        }
        $this->errors[] = $message;
        return $this;
    }



    ##### RETURNS ALL COLLECTED ERROR MESSAGES.

    public function getErrors(): array
    {
        return $this->errors;
    }



    ##### ADDS A DEBUG MESSAGE (ONLY STORED IF DEBUG MODE IS ENABLED).

    public function addDebug(string $message): self
    {
        if ($this->debug) {
            $this->debugLog[] = $message;
        }
        return $this;
    }



    ##### OUTPUTS THE DEBUG LOG (AS HTML COMMENT OR PLAIN TEXT).

    public function getDebug(string $mode = 'output'): void
    {
        if (empty($this->debugLog)) return;
        $out = "=== logFuse Debug ===\n" . implode("\n", $this->debugLog) . "\n=====================\n";
        if ($mode === 'log') error_log($out);
        else echo '<pre>' . htmlspecialchars($out) . '</pre>';
    }



    ########################### INDEX DIRECTORY & FILE SOURCES (INDEX MODE)

    ##### SETS WHERE PERSISTENT INDEX FILES WILL BE STORED (CREATES DIRECTORY IF MISSING).

    public function setIndexDirectory(string $dir): self
    {
        $this->indexDirectory = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!is_dir($this->indexDirectory)) {
            if (!mkdir($this->indexDirectory, 0755, true)) {
                return $this->handleError("Cannot create index directory: {$this->indexDirectory}");
            }
            $this->addDebug("Created index directory: {$this->indexDirectory}");
        }
        if (!is_writable($this->indexDirectory)) {
            return $this->handleError("Index directory not writable: {$this->indexDirectory}");
        }
        $this->addDebug("Index directory set to: {$this->indexDirectory}");
        $this->dirty = true;
        return $this;
    }



    ##### ADDS A PHYSICAL LOG FILE (PATH) TO BE PARSED (FILE MODE).

    public function addFile(string $filePath): self
    {
        if (!is_readable($filePath)) {
            return $this->handleError("Log file not readable: $filePath");
        }
        $realPath = realpath($filePath);
        $this->indexedFile        = $realPath;
        $this->sourceIdentifiers  = ['file:' . $realPath];
        $this->addDebug("Added file: $realPath");
        $this->dirty = true;
        return $this;
    }



    ##### ADDS LOG CONTENT FROM A STRING (AUTOMATIC STABLE ID FROM CONTENT HASH IF NOT PROVIDED).

    public function addFileContent(string $content, ?string $stableId = null): self
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'lf_');
        if ($tempFile === false) {
            return $this->handleError("Cannot create temporary file for content");
        }
        file_put_contents($tempFile, $this->normalizeLineEndings($content));
        $this->tempFiles[]  = $tempFile;
        $this->indexedFile  = $tempFile;

        //-- If no stableId is provided, generate one from the content hash to avoid index duplicates
        if ($stableId === null) {
            $stableId = md5($content);
            $this->addDebug("No stableId provided – using content hash as ID: $stableId");
        }
        $this->sourceIdentifiers = ['content:' . md5($stableId)];
        $this->addDebug("Added content with stable ID: $stableId -> " . md5($stableId));

        $this->dirty = true;
        return $this;
    }



    ########################### PAGINATION, ORDERING AND LIMITS

    ##### SETS CURRENT PAGE NUMBER AND ENTRIES PER PAGE (PAGE SIZE 0 DISABLES PAGINATION).

    public function setPagination(int $pageNumber, int $pageSize): self
    {
        if ($pageNumber < 1) return $this->handleError('Page number must be >= 1');
        if ($pageSize < 0) return $this->handleError('Page size must be >= 0');
        if ($pageSize === 0) {
            $this->addDebug('Page size set to 0 – pagination disabled, using maxEntries or all entries');
        }
        $this->pageNumber = $pageNumber;
        $this->pageSize   = $pageSize;
        $this->addDebug("Pagination set: page $pageNumber, size $pageSize");
        $this->dirty = true;
        return $this;
    }



    ##### DEFINES SORT ORDER: 'desc' (NEWEST FIRST, DEFAULT) OR 'asc' (OLDEST FIRST).

    public function setOrder(string $order): self
    {
        $order = strtolower($order);
        if (!in_array($order, ['desc', 'asc'], true)) {
            return $this->handleError("Order must be 'desc' or 'asc'");
        }
        $this->order = $order;
        $this->addDebug("Order set to: $order");
        $this->dirty = true;
        return $this;
    }



    ##### LIMITS THE TOTAL NUMBER OF ENTRIES LOADED (IGNORED IF PAGINATION IS ACTIVE).

    public function setMaxEntries(?int $maxEntries): self
    {
        if ($maxEntries !== null && $maxEntries < 1) {
            return $this->handleError('Max entries must be >= 1 or null');
        }
        $this->maxEntries = $maxEntries;
        $this->addDebug('Max entries set to: ' . ($maxEntries ?? 'unlimited'));
        $this->dirty = true;
        return $this;
    }



    ##### SETS THE LANGUAGE FOR DATE FORMATTING (EN, DE, TR).

    public function setLanguage(string $lang): self
    {
        if (!in_array($lang, ['en', 'de', 'tr'], true)) {
            return $this->handleError("Language '$lang' not supported. Use 'en', 'de' or 'tr'.");
        }
        $this->language = $lang;
        $this->addDebug("Language set to: $lang");
        return $this;
    }



    ##### SETS THE DEFAULT TIMEZONE FOR PARSING DATES WITHOUT TIMEZONE INFORMATION.

    public function setDefaultTimezone(string $tz): self
    {
        $this->defaultTimezone = $tz;
        $this->addDebug("Default timezone set to: $tz");
        return $this;
    }



    ##### ADDS A CUSTOM REGEX PATTERN FOR DETECTING LOG ENTRY START LINES.

    public function addPattern(string $name, string $regex): self
    {
        $this->userPatterns[$name] = $regex;
        $this->addDebug("Added pattern: $name -> $regex");
        $this->dirty = true;
        return $this;
    }



    ##### DELETES THE CACHED INDEX FILE FOR THE CURRENT SOURCE (FORCES REBUILD).

    public function clearIndexCache(): self
    {
        $indexPath = $this->getIndexPath();
        if (file_exists($indexPath)) {
            @unlink($indexPath);
            $this->addDebug("Deleted index file: $indexPath");
        }
        $this->dirty = true;
        return $this;
    }



    ########################### PUBLIC DATA GETTERS

    ##### RETURNS TOTAL NUMBER OF ENTRIES (INDEX MODE: FROM OFFSET COUNT, TABULAR MODE: FROM LOADED ROWS).

    public function getTotalEntryCount(): int
    {
        if ($this->indexedFile !== null && !$this->tabularMode && empty($this->sourceLoaders)) {
            $offsets = $this->loadOrBuildIndex($this->indexedFile);
            return count($offsets);
        }
        $this->rebuildIfDirty();
        return count($this->entries);
    }



    ##### RETURNS THE RAW ENTRIES (ARRAY OF STRINGS) AFTER PAGINATION/ORDERING.

    public function getEntries(): array
    {
        $this->rebuildIfDirty();
        return $this->entries;
    }



    ##### RETURNS THE PARSED ENTRIES (STRUCTURED ARRAYS WITH DATETIME, LEVEL, MESSAGE, STACKTRACE, ETC.).

    public function getRawData(): array
    {
        $this->rebuildIfDirty();
        return $this->parsedEntries;
    }



    ##### GENERATES THE FINAL OUTPUT (HTML OR JSON) BASED ON CURRENT STATE.

    public function getOutput(string $format = 'html', ?bool $throwOnError = null): string|false
    {
        $this->rebuildIfDirty();
        if (!empty($this->errors)) {
            $this->addDebug('Output aborted due to errors');
            $useThrow = $throwOnError ?? $this->throwExceptions;
            if ($useThrow) {
                throw new \RuntimeException('Errors occurred: ' . implode(', ', $this->errors));
            }
            return false;
        }

        $output = match ($format) {
            'html' => '<ul class="lf-list">' . $this->renderAll() . '</ul>',
            'json' => json_encode($this->parsedEntries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            default => $this->handleError("Unsupported format: $format"),
        };
        if ($output === false) return false;

        //-- Append debug log as comment (HTML) or extra key (JSON)
        if ($this->debug && !empty($this->debugLog)) {
            $debugStr = "DEBUG LOG:\n" . implode("\n", $this->debugLog);
            if ($format === 'html') {
                $output .= "\n<!--\n$debugStr\n-->";
            } elseif ($format === 'json') {
                $data = json_decode($output, true);
                $data['_debug'] = $this->debugLog;
                $output = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
        }
        return $output;
    }



    ##### RETURNS EMBEDDABLE CSS FOR THE GIVEN THEME (LIGHT, DARK, PEACHY, E2).

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



    ########################### UNIFIED ACCESS TO TABULAR DATA SOURCES (CSV, SQLITE, MYSQL, ARRAYS)

    ##### ADDS A TABULAR SOURCE (CSV FILE PATH OR "DSN:TABLE" STRING) WITH OPTIONAL COLUMN MAPPING.

    public function addTabularSource(string $source, $mapping = null, array $options = []): self
    {
        $this->tabularMode = true;
        if (preg_match('/\.csv$/i', $source) && is_file($source)) {
            return $this->addCsvSource($source, $mapping, $options);
        }
        $colonPos = strrpos($source, ':');
        if ($colonPos !== false) {
            $dsn   = substr($source, 0, $colonPos);
            $table = substr($source, $colonPos + 1);
            if (!empty($dsn) && !empty($table)) {
                $this->addDatabaseSourceLazy($dsn, $table, $mapping, $options);
                return $this;
            }
        }
        return $this->handleError('Invalid source format. Expected CSV file path (.csv) or "dsn:table".');
    }



    ##### ADDS AN ITERABLE DATA SET WITH A CUSTOM ROW‑TO‑STRING FORMATTER (TABULAR MODE).

    public function addTabularData(iterable $rows, callable $rowFormatter): self
    {
        $this->tabularMode = true;
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



    ##### INTERNAL: HANDLES CSV SOURCE (READS ROWS, APPLIES MAPPING/FORMATTER).

    private function addCsvSource(string $file, $mapping, array $options): self
    {
        if (!is_readable($file)) return $this->handleError("CSV not readable: $file");
        $delimiter = $options['csv_delimiter'] ?? ',';
        $hasHeader = $options['csv_header'] ?? false;
        $fileObj = new \SplFileObject($file);
        $fileObj->setFlags(\SplFileObject::READ_CSV);
        $fileObj->setCsvControl($delimiter);
        if ($hasHeader) {
            $fileObj->current();
            $fileObj->next();
        }
        $formatter = $this->buildRowFormatter($mapping, $options);
        return $this->addTabularData($fileObj, $formatter);
    }



    ##### INTERNAL: LAZY LOADER FOR DATABASE SOURCES (SQLITE/PDO).

    private function addDatabaseSourceLazy(string $dsn, string $table, $mapping, array $options): void
    {
        $this->sourceLoaders[] = function () use ($dsn, $table, $mapping, $options) {
            return $this->loadDatabaseRows($dsn, $table, $mapping, $options);
        };
        $this->dirty = true;
    }



    ##### INTERNAL: ACTUALLY QUERIES THE DATABASE AND RETURNS ROWS AS STRING LINES.

    private function loadDatabaseRows(string $dsn, string $table, $mapping, array $options): array
    {
        $db = $this->connectDatabaseWithRetry($dsn, $options);
        if ($db === null) return [];

        $limit = $this->pageSize;
        if ($limit == 0) {
            $effective = $this->getEffectiveRowLimit();
            if ($effective !== null) $limit = $effective;
        }
        $offset = ($this->pageNumber - 1) * $this->pageSize;

        $sql = "SELECT * FROM " . $this->escapeIdentifier($table);
        if ($limit > 0) $sql .= " LIMIT " . (int)$limit;
        if ($offset > 0) $sql .= " OFFSET " . (int)$offset;

        $rows = [];
        if ($db instanceof \SQLite3) {
            $result = $db->query($sql);
            if ($result) while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
        } elseif ($db instanceof \PDO) {
            $stmt = $db->query($sql);
            if ($stmt) $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        $formatter = $this->buildRowFormatter($mapping, $options);
        $lines = [];
        foreach ($rows as $row) $lines[] = $this->normalizeLineEndings($formatter($row));
        return $lines;
    }



    ##### INTERNAL: CONNECTS TO DATABASE WITH ONE RETRY.

    private function connectDatabaseWithRetry(string $dsn, array $options)
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                if (str_starts_with($dsn, 'sqlite:')) {
                    $dbFile = substr($dsn, 7);
                    $dbDir = dirname($dbFile);
                    if (!is_dir($dbDir)) mkdir($dbDir, 0755, true);
                    return new \SQLite3($dbFile);
                }
                $username = $options['username'] ?? '';
                $password = $options['password'] ?? '';
                $driverOptions = $options['driver_options'] ?? [];
                return new \PDO($dsn, $username, $password, $driverOptions);
            } catch (\Exception $e) {
                if ($attempt >= 2) return $this->handleDatabaseError($e);
                usleep(100000);
            }
        }
        return null;
    }



    ##### INTERNAL: HANDLES DATABASE CONNECTION ERRORS.

    private function handleDatabaseError(\Exception $e)
    {
        $msg = "Database connection failed: " . $e->getMessage();
        if ($this->throwExceptions) throw new \RuntimeException($msg, 0, $e);
        $this->errors[] = $msg;
        return null;
    }



    ##### INTERNAL: BUILDS A CALLABLE THAT TURNS A DATABASE/CSV ROW INTO A LOG LINE STRING.

    private function buildRowFormatter($mapping, array $options): callable
    {
        if (is_callable($mapping)) return $mapping;
        if ($mapping === null) {
            return fn($row) => sprintf(
                '[%s] %s: %s',
                $row['datetime'] ?? $row['timestamp'] ?? $row['created_at'] ?? '',
                $row['level'] ?? $row['severity'] ?? 'INFO',
                $row['message'] ?? $row['msg'] ?? (is_array($row) ? json_encode($row) : (string)$row)
            );
        }
        if (isset($mapping[0], $mapping[1], $mapping[2])) {
            return fn($row) => sprintf(
                '[%s] %s: %s',
                $row[$mapping[0]] ?? '',
                $row[$mapping[1]] ?? 'INFO',
                $row[$mapping[2]] ?? ''
            );
        }
        if (isset($mapping['datetime']) || isset($mapping['level']) || isset($mapping['message'])) {
            return function ($row) use ($mapping) {
                $dt = $mapping['datetime'] ? ($row[$mapping['datetime']] ?? '') : '';
                $lvl = $mapping['level'] ? ($row[$mapping['level']] ?? 'INFO') : 'INFO';
                $msg = $this->buildMessageFromMapping($row, $mapping['message'] ?? '');
                return "[$dt] $lvl: $msg";
            };
        }
        return fn($row) => is_array($row) ? implode(' | ', $row) : (string)$row;
    }



    ##### INTERNAL: BUILDS A MESSAGE STRING FROM A ROW USING MAPPING RULES (FIELDS, TEMPLATE, ETC).

    private function buildMessageFromMapping(array $row, $messageDef): string
    {
        if (is_string($messageDef) && isset($row[$messageDef])) return (string)$row[$messageDef];
        if (is_string($messageDef) && strpos($messageDef, '{') !== false) {
            return preg_replace_callback('/\{([a-zA-Z0-9_]+)\}/', fn($m) => $row[$m[1]] ?? '', $messageDef);
        }
        if (is_array($messageDef) && isset($messageDef['fields'])) {
            $parts = [];
            foreach ($messageDef['fields'] as $field) $parts[] = $row[$field] ?? '';
            $glue = $messageDef['glue'] ?? ' ';
            $prefix = $messageDef['prefix'] ?? '';
            $suffix = $messageDef['suffix'] ?? '';
            return $prefix . implode($glue, $parts) . $suffix;
        }
        return '';
    }



    ##### INTERNAL: ESCAPES SQL IDENTIFIERS (TABLE/COLUMN NAMES) FOR SECURITY.

    private function escapeIdentifier(string $name): string
    {
        if (preg_match('/[^a-zA-Z0-9_\.]/', $name)) throw new \InvalidArgumentException("Invalid identifier: $name");
        return '`' . str_replace('`', '``', $name) . '`';
    }



    ##### INTERNAL: DETERMINES THE EFFECTIVE ROW LIMIT FOR TABULAR MODE (PAGE SIZE OR MAX ENTRIES).

    private function getEffectiveRowLimit(): ?int
    {
        if ($this->pageSize > 0) return $this->pageSize;
        if ($this->maxEntries !== null) return $this->maxEntries * 5;
        return 10000;
    }



    ########################### CORE REBUILD LOGIC (MODE SWITCH)

    ##### REBUILDS THE INTERNAL STATE ONLY IF SOMETHING HAS CHANGED (DIRTY FLAG).

    private function rebuildIfDirty(): void
    {
        if (!$this->dirty) return;
        $this->rebuild();
        $this->dirty = false;
    }



    ##### MAIN REBUILD: DISTINGUISHES BETWEEN TABULAR MODE AND INDEX MODE.

    private function rebuild(): void
    {
        $this->entries        = [];
        $this->parsedEntries  = [];

        if ($this->isTabularMode()) {
            $rawLines = $this->collectTabularLines();
            $this->entries = $this->applyPaginationAndOrder($rawLines);
        } elseif ($this->indexedFile !== null) {
            $this->entries = $this->buildIndexEntries();
        } else {
            return;
        }

        //-- Parse all entries once (no duplication)
        foreach ($this->entries as $entry) {
            $this->parsedEntries[] = $this->analyze($entry);
        }
        $this->parseCache = [];
    }



    ##### CHECKS IF THE CURRENT MODE IS TABULAR (EXPLICIT OR VIA LOADERS).

    private function isTabularMode(): bool
    {
        return $this->tabularMode || !empty($this->sourceLoaders);
    }



    ##### COLLECTS ALL RAW LINES FROM ALL TABULAR SOURCES.

    private function collectTabularLines(): array
    {
        $allRawLines = [];
        foreach ($this->sourceLoaders as $loader) {
            $lines = $loader();
            foreach ($lines as $line) {
                $allRawLines[] = $line;
            }
        }
        $this->sourceLoaders = []; // consumed
        return $allRawLines;
    }



    ##### APPLIES ORDER, MAX ENTRIES AND PAGINATION TO A RAW LINE ARRAY.

    private function applyPaginationAndOrder(array $lines): array
    {
        if ($this->order === 'desc') {
            $lines = array_reverse($lines);
        }
        if ($this->maxEntries !== null && $this->maxEntries > 0) {
            $lines = array_slice($lines, 0, $this->maxEntries);
        }
        if ($this->pageSize > 0) {
            $offset = ($this->pageNumber - 1) * $this->pageSize;
            $lines = array_slice($lines, $offset, $this->pageSize);
        }
        return $lines;
    }



    ##### BUILDS ENTRIES FROM THE INDEXED FILE (BYTE OFFSETS) WITH PROPER PAGINATION.

    private function buildIndexEntries(): array
    {
        $logFile = $this->indexedFile;
        if (!is_readable($logFile)) {
            $this->handleError("Indexed file not readable: $logFile");
            return [];
        }

        $offsets = $this->loadOrBuildIndex($logFile);
        if (empty($offsets)) {
            return [];
        }

        $total = count($offsets);
        $limit = $this->pageSize > 0 ? $this->pageSize : ($this->maxEntries ?? $total);

        if ($this->order === 'desc') {
            //-- mathematically correct DESC pagination (newest first)
            $startIdx = max(0, $total - $this->pageNumber * $limit);
            $endIdx   = $total - ($this->pageNumber - 1) * $limit - 1;
            if ($startIdx > $endIdx) {
                return [];
            }
            $length = $endIdx - $startIdx + 1;
            $slice = array_slice($offsets, $startIdx, $length);
            $rawEntries = $this->readEntriesByOffsets($logFile, $slice);
            return array_reverse($rawEntries);
        } else {
            $startIdx = ($this->pageNumber - 1) * $limit;
            if ($startIdx >= $total) {
                return [];
            }
            $length = min($limit, $total - $startIdx);
            $slice = array_slice($offsets, $startIdx, $length);
            return $this->readEntriesByOffsets($logFile, $slice);
        }
    }



    ########################### INDEX HELPERS (BYTE‑OFFSET INDEX)

    ##### RETURNS THE INDEX DIRECTORY PATH (DEFAULT: SYSTEM TEMP + /LOGFUSE_CACHE).

    private function getIndexDirectoryPath(): string
    {
        if ($this->indexDirectory !== '') return $this->indexDirectory;
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logfuse_cache';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Cannot create cache directory: $dir");
        }
        return $dir;
    }



    ##### BUILDS THE FULL PATH TO THE INDEX FILE (BASED ON SOURCE HASH).

    private function getIndexPath(): string
    {
        $signature = [
            'sources'  => $this->sourceIdentifiers,
            'patterns' => $this->userPatterns,
            'timezone' => $this->defaultTimezone,
        ];
        $hash = md5(serialize($signature));
        return $this->getIndexDirectoryPath() . DIRECTORY_SEPARATOR . $hash . '.logfuse';
    }



    ##### DETECTS WHETHER A LINE MARKS THE START OF A NEW LOG ENTRY.

    private function isEntryStart(string $line): bool
    {
        if ($line === '') return false;
        $firstChar = $line[0];
        if ($firstChar === ' ' || $firstChar === "\t" || $firstChar === "\n" || $firstChar === "\r") return false;

        // Single most common pattern – ISO date at line start
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $line)) return true;

        // Other patterns as fallback
        $patterns = [
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\]/',
            '/^\[[0-9]{2}-[A-Za-z]{3}-[0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} [A-Za-z\/_]+\]/',
            '/^\[[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2}\.\d+ \d{4}\]/',
            '/^\[[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2} \d{4}\]/',
            '/^[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2}\.\d+ \d{4}/',
            '/^[A-Za-z]{3} [A-Za-z]{3} \d{2} \d{2}:\d{2}:\d{2} \d{4}/',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $line)) return true;
        }

        // User patterns
        foreach ($this->userPatterns as $pattern) {
            if (preg_match($pattern, $line)) return true;
        }
        return false;
    }



    ##### SCANS THE ENTIRE LOG FILE AND RETURNS AN ARRAY OF BYTE OFFSETS (START OF EACH ENTRY).

    private function buildFullIndex(string $logFile): array
    {
        $this->addDebug("Building full index for: $logFile");
        $fp = @fopen($logFile, 'r');
        if (!$fp) {
            $this->handleError("Cannot open file for indexing: $logFile");
            return [];
        }
        $offsets = [];
        while (!feof($fp)) {
            $pos = ftell($fp);
            $line = fgets($fp);
            if ($line === false) break;
            if ($this->isEntryStart($line)) $offsets[] = $pos;
        }
        fclose($fp);
        $this->addDebug('Found ' . count($offsets) . ' entry offsets');
        return $offsets;
    }



    ##### WRITES THE INDEX FILE (SERIALIZED OFFSETS + FILE SIZE + MTIME).

    private function saveIndex(string $indexFile, array $offsets, int $size, int $mtime): void
    {
        $data = serialize(['size' => $size, 'mtime' => $mtime, 'offsets' => $offsets]);
        if (file_put_contents($indexFile, $data, LOCK_EX) === false) {
            $this->handleError("Cannot write index file: $indexFile");
        }
        $this->addDebug("Saved index: $indexFile (size $size, mtime $mtime, offsets " . count($offsets) . ')');
    }



    ##### LOADS EXISTING INDEX OR BUILDS A NEW ONE IF OUTDATED / MISSING.

    private function loadOrBuildIndex(string $logFile): array
    {
        $indexPath = $this->getIndexPath();
        $currentSize = @filesize($logFile);
        $currentMtime = @filemtime($logFile);
        if ($currentSize === false || $currentMtime === false) {
            $this->handleError("Cannot stat log file: $logFile");
            return [];
        }

        if (file_exists($indexPath)) {
            $data = @unserialize(@file_get_contents($indexPath));
            if ($data && isset($data['size'], $data['mtime'], $data['offsets'])) {
                if ($currentSize === $data['size'] && $currentMtime === $data['mtime']) {
                    $this->addDebug("Using cached index (size=$currentSize, mtime=$currentMtime)");
                    return $data['offsets'];
                } else {
                    $this->addDebug('Index outdated');
                }
            } else {
                $this->addDebug('Index file corrupt, rebuilding');
            }
        }

        $this->addDebug('Building new index');
        $offsets = $this->buildFullIndex($logFile);
        $this->saveIndex($indexPath, $offsets, $currentSize, $currentMtime);
        return $offsets;
    }



    ##### READS RAW ENTRY TEXTS GIVEN AN ARRAY OF BYTE OFFSETS.

    private function readEntriesByOffsets(string $logFile, array $offsets): array
    {
        if (empty($offsets)) return [];
        $fp = @fopen($logFile, 'r');
        if (!$fp) {
            $this->handleError("Cannot open file for reading entries: $logFile");
            return [];
        }
        $fileSize = filesize($logFile);
        $entries = [];
        $num = count($offsets);
        for ($i = 0; $i < $num; $i++) {
            $start = $offsets[$i];
            $end = ($i + 1 < $num) ? $offsets[$i + 1] : $fileSize;
            if ($start >= $fileSize) continue;
            $length = $end - $start;
            if ($length <= 0) continue;
            fseek($fp, $start);
            $entry = fread($fp, $length);
            if ($entry === false) {
                $this->handleError("Error reading entry at offset $start");
                continue;
            }
            if (strlen($entry) < $length) {
                $this->addDebug("Short read at offset $start: expected $length, got " . strlen($entry));
            }
            $entries[] = rtrim($entry, "\n\r");
        }
        fclose($fp);
        $this->addDebug('Read ' . count($entries) . ' entries by offsets');
        return $entries;
    }



    ########################### RENDERING

    ##### RENDERS ALL PARSED ENTRIES INTO AN HTML STRING.

    private function renderAll(): string
    {
        $html = '';
        foreach ($this->parsedEntries as $parsed) {
            $html .= $this->renderParsedEntry($parsed);
        }
        return $html;
    }



    ##### RENDERS A SINGLE PARSED ENTRY (DATETIME, LEVEL, MESSAGE, FILE, STACKTRACE).

    private function renderParsedEntry(array $parsed): string
    {
        $datetime   = $parsed['datetime'];
        $level      = $parsed['level'];
        $message    = $parsed['message'];
        $file       = $parsed['file'];
        $lineNo     = $parsed['line'];
        $stacktrace = $parsed['stacktrace'];

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
                        if ($item['line'] > 0) $html .= ':' . $item['line'];
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



    ########################### PARSING (EXTRACTS DATETIME, LEVEL, MESSAGE, FILE, STACKTRACE)

    ##### MAIN ANALYZE FUNCTION: APPLIES PATTERNS OR FALLBACK TO EXTRACT STRUCTURED DATA.

    private function analyze(string $entry): array
    {
        $this->addDebug('----- ANALYZE NEW ENTRY -----');
        $this->addDebug('RAW ENTRY (first 300 chars): ' . substr($entry, 0, 300));
        if (array_key_exists($entry, $this->parseCache)) {
            $this->addDebug('CACHED RESULT');
            return $this->parseCache[$entry];
        }
        $lines = explode("\n", $entry);
        $firstLine = array_shift($lines);
        $rest = implode("\n", $lines);
        $firstLine = rtrim($firstLine);
        $this->addDebug('FIRST LINE: ' . $firstLine);
        $this->addDebug('REST LINES: ' . (empty($rest) ? '(empty)' : substr($rest, 0, 300)));

        $result = $this->tryFullPatterns($firstLine, $rest);
        if ($result !== null) {
            $this->parseCache[$entry] = $result;
            return $result;
        }
        $result = $this->modularFallback($firstLine, $rest);
        $this->parseCache[$entry] = $result;
        return $result;
    }



    ##### ATTEMPTS TO MATCH THE ENTRY AGAINST BUILT‑IN AND USER PATTERNS.

    private function tryFullPatterns(string $firstLine, string $rest): ?array
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
                $this->addDebug('PATTERN MATCHED: ' . $name);
                $datetime = $m['datetime'] ?? '';
                $level    = $m['level'] ?? 'app';
                $message  = $m['message'] ?? '';
                $file = '';
                $lineNo = 0;
                //-- Try to extract "in file.php:123" from message
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



    ##### FALLBACK PARSER: EXTRACTS DATETIME, LEVEL, MESSAGE STEP BY STEP.

    private function modularFallback(string $firstLine, string $rest): array
    {
        $this->addDebug('MODULAR FALLBACK');
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



    ##### EXTRACTS DATETIME FROM THE BEGINNING OF A LINE USING MULTIPLE PATTERNS.

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
            if (preg_match($pattern, $line, $m)) return $m['datetime'];
        }
        return '';
    }



    ##### REMOVES THE EXTRACTED DATETIME PART FROM THE LINE.

    private function removeDatetimeFromLine(string $line, string $datetime): string
    {
        if (str_starts_with($line, '[' . $datetime . ']')) {
            return trim(substr($line, strlen('[' . $datetime . ']')));
        }
        if (str_starts_with($line, $datetime)) {
            return trim(substr($line, strlen($datetime)));
        }
        $pattern = '/^(\[?' . preg_quote($datetime, '/') . '\]?)/';
        return trim(preg_replace($pattern, '', $line, 1));
    }



    ##### EXTRACTS LOG LEVEL (E.G., "ERROR:", "[WARNING]") FROM THE LINE.

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



    ##### PARSES STACK TRACE FROM THE REMAINING LINES AFTER THE FIRST LINE.

    private function parseStacktrace(string $rest, string $message): array
    {
        $stacktrace = [];
        if ($rest === '') return $stacktrace;

        $stackBlock = '';
        if (preg_match('/^(Stack trace:.*)$/s', $rest, $sm)) {
            $stackBlock = $sm[1];
        } elseif (preg_match('/^(#\d+.*)$/m', $rest, $sm)) {
            $stackBlock = $sm[1];
        }

        if ($stackBlock !== '') {
            $this->addDebug('STACK BLOCK RAW (first 200): ' . substr($stackBlock, 0, 200));
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
                } elseif ($inStack && preg_match('/^#(\d+)\s+(.*)$/', $stackLine, $m)) {
                    $stacktrace[] = [
                        'type'   => 'entry',
                        'number' => $m[1],
                        'file'   => '',
                        'line'   => 0,
                        'call'   => $m[2]
                    ];
                } elseif ($inStack && $stackLine === '{main}') {
                    $stacktrace[] = [
                        'type'   => 'entry',
                        'number' => 'main',
                        'file'   => '',
                        'line'   => 0,
                        'call'   => '{main}'
                    ];
                } elseif ($inStack && preg_match('/^thrown in /', $stackLine)) {
                    $stacktrace[] = $stackLine;
                } else {
                    if ($inStack) $stacktrace[] = $stackLine;
                }
            }
        }
        return $stacktrace;
    }



    ########################### DATE FORMATTING

    ##### FORMATS A TIMESTAMP OR DATE STRING INTO A HUMAN‑READABLE, LOCALISED REPRESENTATION.

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

        $timestamp = false;
        $tz = new \DateTimeZone($this->defaultTimezone);

        if ($timeElem === false || $timeElem === null || $timeElem === '') {
            $timestamp = time();
        } elseif (is_numeric($timeElem)) {
            $timestamp = (int)$timeElem;
        } elseif (is_string($timeElem)) {
            $timeElem = preg_replace('/\s+[A-Za-z\/]+$/', '', $timeElem);
            $formats = [
                'd-M-Y H:i:s',
                'D M d H:i:s.u Y',
                'D M d H:i:s Y',
                'Y-m-d H:i:s',
            ];
            $date = false;
            foreach ($formats as $format) {
                $date = \DateTime::createFromFormat($format, $timeElem, $tz);
                if ($date !== false) break;
            }
            if ($date === false) {
                $timestamp = strtotime($timeElem);
                if ($timestamp === false) $timestamp = 0;
            } else {
                $timestamp = $date->getTimestamp();
            }
        }
        if (!$timestamp || $timestamp <= 0) return 'n/a';

        $day = (int) date('j', $timestamp);
        $month = (int) date('n', $timestamp);
        $year = (int) date('Y', $timestamp);
        $todayBegin = strtotime(date('Y-m-d') . ' 00:00:00');
        $prev2Begin = $todayBegin - 86400 * 2;
        $prev1Begin = $todayBegin - 86400;

        if ($timestamp >= $prev2Begin && $timestamp < $todayBegin) {
            if ($timestamp < $prev1Begin) return $relative[0] . ', ' . date('H:i:s', $timestamp);
            else return $relative[1] . ', ' . date('H:i:s', $timestamp);
        }
        if ($timestamp >= $todayBegin && $timestamp < $todayBegin + 86400) {
            return $relative[2] . ', ' . date('H:i:s', $timestamp);
        }
        $yearStr = ($year == date('Y')) ? '' : ' ' . $year;
        return $day . '. ' . $months[$month] . $yearStr . ', ' . date('H:i:s', $timestamp);
    }



    ##### NORMALIZES LINE ENDINGS (CRLF, CR) TO UNIX LF.

    private function normalizeLineEndings(string $line): string
    {
        return str_replace(["\r\n", "\r"], "\n", $line);
    }
}
