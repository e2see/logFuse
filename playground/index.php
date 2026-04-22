<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../src/logFuse.php';
require_once __DIR__ . '/sub/functions.php';

//-- Auto-create demo database if missing
initLegacyDatabase(__DIR__ . '/logs/');

use e2\logFuse;

$logDir = __DIR__ . '/logs/';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

//-- List all .log files
$logFiles = glob($logDir . '*.log');
$logFiles = array_map('basename', $logFiles);
sort($logFiles);

//-- Read GET parameters
$selectedLog     = $_GET['logFile']     ?? ($logFiles[0] ?? '');
$language        = $_GET['language']    ?? 'en';
$outputFormat    = $_GET['outputFormat'] ?? 'html';
$order           = $_GET['order']       ?? 'desc';
$pageNumber      = max(1, (int)($_GET['page'] ?? 1));
$pageSize        = (int)($_GET['pageSize'] ?? 0);
$theme           = $_GET['theme']       ?? 'peachy';
$timezoneStr     = $_GET['timezone']    ?? date_default_timezone_get();

//-- Set timezone for the entire request
date_default_timezone_set($timezoneStr);
$timezone = new DateTimeZone($timezoneStr);

$userInput      = '';
$resultOutput   = '';
$parseError     = '';
$totalEntries   = 0;
$previewContent = '';

if ($selectedLog) {
    //-- ============================================================
    //-- 1) CSV file (addTabularSource demo)
    //-- ============================================================
    if (str_starts_with($selectedLog, 'csv:')) {
        $csvFile = substr($selectedLog, 4);
        $csvPath = $logDir . $csvFile;

        if (file_exists($csvPath)) {
            $previewContent = generateCsvPreview($csvPath, 20, $timezone);

            $log = new logFuse([
                'debug'    => false,
                'timezone' => $timezoneStr,
            ]);

            $log->addTabularSource($csvPath, ['datetime', 'level', 'message'], ['csv_header' => true]);

            $log->setLanguage($language)
                ->setOrder($order)
                ->setPagination($pageNumber, $pageSize);

            $totalEntries = $log->getTotalEntryCount();
            $output = $log->getOutput($outputFormat);

            if ($output !== false) {
                if ($outputFormat === 'html') {
                    $resultOutput = replaceFormattedMagicDatesUsingLogFuse($output, $log, $timezone);
                } else {
                    $resultOutput = $output;
                }
            } else {
                $parseError = implode(', ', $log->getErrors());
            }
        } else {
            $parseError = "CSV file not found: $csvPath";
        }
    }

    //-- ============================================================
    //-- 2) Legacy SQLite database (standard 3 columns)
    //-- ============================================================
    elseif (str_starts_with($selectedLog, 'legacy:')) {
        $dbPath = __DIR__ . '/logs/demo_logs.sqlite';
        $table  = 'logs';

        if (file_exists($dbPath)) {
            //-- Preview: ASCII table (with replaced dates for readability)
            $previewContent = generateLegacyTablePreview($dbPath, $table, 20, $timezone);

            $log = new logFuse([
                'debug'    => false,
                'timezone' => $timezoneStr,
            ]);

            //-- Standard 3-column mapping: log_time, log_level, log_message
            //-- Data was inserted oldest-first. logFuse reads top-to-bottom.
            //-- With setOrder('desc') it will reverse, showing newest first.
            $log->addTabularSource("sqlite:$dbPath:$table", ['log_time', 'log_level', 'log_message']);

            $log->setLanguage($language)
                ->setOrder($order)
                ->setPagination($pageNumber, $pageSize);

            $totalEntries = $log->getTotalEntryCount();
            $output = $log->getOutput($outputFormat);

            if ($output !== false) {
                if ($outputFormat === 'html') {
                    $resultOutput = replaceFormattedMagicDatesUsingLogFuse($output, $log, $timezone);
                } else {
                    $resultOutput = $output;
                }
            } else {
                $parseError = implode(', ', $log->getErrors());
            }
        } else {
            $parseError = "Legacy database not found: $dbPath";
        }
    }

    //-- ============================================================
    //-- 3) Normal .log file
    //-- ============================================================
    elseif (file_exists($logDir . $selectedLog)) {
        $realPath = realpath($logDir . $selectedLog);
        $realLogDir = realpath($logDir);
        if ($realPath === false || strpos($realPath, $realLogDir) !== 0) {
            $parseError = "Invalid file path.";
        } else {
            $raw = file_get_contents($realPath);
            if ($raw !== false) {
                $userInput = $raw;
                $previewContent = $raw;

                $log = new logFuse([
                    'debug'    => false,
                    'timezone' => $timezoneStr,
                ]);

                $log->addFileContent($raw)
                    ->setLanguage($language)
                    ->setOrder($order)
                    ->setPagination($pageNumber, $pageSize);

                $totalEntries = $log->getTotalEntryCount();
                $output = $log->getOutput($outputFormat);

                if ($output !== false) {
                    if ($outputFormat === 'html') {
                        $resultOutput = replaceFormattedMagicDatesUsingLogFuse($output, $log, $timezone);
                    } else {
                        $resultOutput = $output;
                    }
                } else {
                    $parseError = implode(', ', $log->getErrors());
                }
            } else {
                $parseError = "Log file could not be read: " . htmlspecialchars($selectedLog);
            }
        }
    }
}

$logFuseCss = logFuse::getCss($theme);
$isHtml = ($outputFormat === 'html');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>logFuse Playground – Log Parser &amp; Formatter</title>
    <link rel="icon" type="image/png" href="../images/logo-xs.png">
    <link rel="stylesheet" href="sub/core.css">
    <link rel="stylesheet" href="sub/template.css">
    <link rel="stylesheet" href="sub/components.css">
    <link rel="stylesheet" href="sub/desktop.css">
    <style>
        <?= $logFuseCss ?><?php if ($isHtml): ?>.output-container.theme-e2 {
            background: #041e28;
        }

        .output-container.theme-dark {
            background: #202020;
        }

        .output-container.theme-light {
            background: #f8f8f8;
        }

        .output-container.theme-peachy {
            background: #f1e9e9;
        }

        <?php endif; ?>
    </style>
    <script src="sub/script.js" defer></script>
</head>

<body>
    <a class="logo-container" href="./"><img src="../images/logo-m.png" alt="logFuse Logo" id="logo"></a>
    <h1>logFuse Playground</h1>

    <form method="get" id="mainForm" class="card sticky-input">
        <div class="card-body">
            <div class="toolbar-selects">
                <div class="field-group">
                    <label>📄 Log file</label>
                    <select name="logFile" id="logFileSelect">
                        <?= renderLogFileDropdown($selectedLog, $logFiles, $logDir) ?>
                    </select>
                </div>

                <div class="field-group">
                    <label>🎨 Output format</label>
                    <select name="outputFormat" id="outputFormatSelect">
                        <option value="html" <?= $outputFormat === 'html' ? 'selected' : '' ?>>HTML (styled)</option>
                        <option value="json" <?= $outputFormat === 'json' ? 'selected' : '' ?>>JSON (raw)</option>
                    </select>
                </div>

                <div class="field-group">
                    <label>🎨 Theme</label>
                    <select name="theme" id="themeSelect" <?= !$isHtml ? 'disabled' : '' ?>>
                        <option value="peachy" <?= $theme === 'peachy' ? 'selected' : '' ?>>Peachy</option>
                        <option value="light" <?= $theme === 'light' ? 'selected' : '' ?>>Light</option>
                        <option value="dark" <?= $theme === 'dark' ? 'selected' : '' ?>>Dark</option>
                        <option value="e2" <?= $theme === 'e2' ? 'selected' : '' ?>>e2 (original)</option>
                    </select>
                </div>

                <div class="field-group">
                    <label>🌍 Language (Date)</label>
                    <select name="language" id="languageSelect" <?= !$isHtml ? 'disabled' : '' ?>>
                        <option value="en" <?= $language === 'en' ? 'selected' : '' ?>>English</option>
                        <option value="de" <?= $language === 'de' ? 'selected' : '' ?>>Deutsch</option>
                        <option value="tr" <?= $language === 'tr' ? 'selected' : '' ?>>Türkçe</option>
                    </select>
                </div>

                <div class="field-group">
                    <label>🕒 Timezone</label>
                    <select name="timezone" id="timezoneSelect">
                        <option value="UTC" <?= $timezoneStr === 'UTC' ? 'selected' : '' ?>>UTC</option>
                        <option value="Europe/Berlin" <?= $timezoneStr === 'Europe/Berlin' ? 'selected' : '' ?>>Europe/Berlin</option>
                        <option value="America/New_York" <?= $timezoneStr === 'America/New_York' ? 'selected' : '' ?>>America/New_York</option>
                        <option value="Asia/Tokyo" <?= $timezoneStr === 'Asia/Tokyo' ? 'selected' : '' ?>>Asia/Tokyo</option>
                    </select>
                </div>

                <div class="field-group">
                    <label>🔄 Order</label>
                    <select name="order" id="orderSelect">
                        <option value="desc" <?= $order === 'desc' ? 'selected' : '' ?>>Newest first</option>
                        <option value="asc" <?= $order === 'asc' ? 'selected' : '' ?>>Oldest first</option>
                    </select>
                </div>

                <div class="field-group">
                    <label>📄 Page number</label>
                    <input type="number" name="page" value="<?= $pageNumber ?>" min="1" class="page-input" />
                </div>

                <div class="field-group">
                    <label>Page size</label>
                    <select name="pageSize" id="pageSizeSelect">
                        <option value="0" <?= $pageSize == 0 ? 'selected' : '' ?>>All</option>
                        <option value="5" <?= $pageSize == 5 ? 'selected' : '' ?>>5</option>
                        <option value="10" <?= $pageSize == 10 ? 'selected' : '' ?>>10</option>
                        <option value="20" <?= $pageSize == 20 ? 'selected' : '' ?>>20</option>
                        <option value="50" <?= $pageSize == 50 ? 'selected' : '' ?>>50</option>
                    </select>
                </div>
            </div>

            <div class="toolbar-status">
                <div class="status-info">
                    <span class="info-text">Total entries: <?= $totalEntries ?></span>
                    <?php if ($pageSize > 0 && $totalEntries > 0):
                        $start = ($pageNumber - 1) * $pageSize + 1;
                        $end = min($pageNumber * $pageSize, $totalEntries);
                    ?>
                        <span class="info-text">Showing page <?= $pageNumber ?> (<?= $start ?> – <?= $end ?> of <?= $totalEntries ?>)</span>
                    <?php elseif ($totalEntries > 0): ?>
                        <span class="info-text">Showing all <?= $totalEntries ?> entries</span>
                    <?php endif; ?>
                </div>
                <span id="status" class="status">Ready</span>
            </div>
        </div>
    </form>

    <div class="query-grid-2">
        <div class="query-card">
            <h4>📝 Raw Log Content (readonly)</h4>
            <textarea readonly rows="20" class="log-textarea" id="logDisplay"><?= htmlspecialchars($previewContent ?: $userInput) ?></textarea>
        </div>
        <div class="query-card">
            <h4>✨ Formatted Output</h4>
            <div class="output-container theme-<?= htmlspecialchars($theme) ?>">
                <?php if ($parseError): ?>
                    <div class="message error"><?= htmlspecialchars($parseError) ?></div>
                <?php elseif ($outputFormat === 'html' && $resultOutput): ?>
                    <?= $resultOutput ?>
                <?php elseif ($outputFormat === 'json' && $resultOutput): ?>
                    <pre class="json-output"><?= htmlspecialchars($resultOutput) ?></pre>
                <?php else: ?>
                    <em>Select a log file – output appears automatically.</em>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="grid-2" style="margin-top: 2rem;">
        <div class="card">
            <h3>ℹ️ About logFuse</h3>
            <div class="scrollable">
                <p><strong>Supported formats:</strong> Apache, PHP error, MySQL/MariaDB, custom app logs.</p>
                <p><strong>Features:</strong> Multi-line grouping, stacktrace extraction, datetime formatting (en/de/tr), HTML/JSON output.</p>
                <p><strong>Magic Dates (Demo only):</strong> The sample logs use <b>2111-01-01 11:11:XX</b> as static placeholders.<br>
                    <small>The playground replaces the formatted output (e.g. "1. Jan 2111, 11:11:00") with relative terms like <i>today, 11:11:00</i>, <i>yesterday, 11:11:01</i>, etc., based on the seconds (<b>XX</b>).
                        This keeps the demo ever‑fresh – but real logFuse works with any real timestamp. Drop your own <code>.log</code> files into the <code>logs/</code> folder to test with real data.</small>
                </p>
            </div>
        </div>
        <div class="card">
            <h3>🎨 CSS Customization</h3>
            <div class="scrollable">
                <p>The HTML output uses <code>.lf-*</code> classes. You can override CSS variables:</p>
                <pre>--lf-level-error: #e25950;<br />--lf-level-info: #ff9f48;<br />--lf-datetime-color: rgb(0,91,118);<br />--lf-progress: 50%;</pre>
            </div>
        </div>
    </div>
</body>

</html>