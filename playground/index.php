<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../src/class.e2.logFuse.php';

use e2\logFuse;

$logDir = __DIR__ . '/logs/';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);

function replaceDatePlaceholders(string $content): string
{
    $now = new DateTime();
    $content = str_replace('{{TODAY}}', $now->format('Y-m-d H:i:s'), $content);
    $content = str_replace('{{YESTERDAY}}', (clone $now)->modify('-1 day')->format('Y-m-d H:i:s'), $content);
    $content = preg_replace_callback('/\{\{DAYS_AGO:(\d+)\}\}/', function ($m) use ($now) {
        return (clone $now)->modify("-{$m[1]} days")->format('Y-m-d H:i:s');
    }, $content);
    $content = str_replace('{{LAST_YEAR}}', (clone $now)->modify('-1 year')->format('Y-m-d H:i:s'), $content);
    $content = str_replace('{{APACHE_TODAY}}', $now->format('D M d H:i:s.v Y'), $content);
    $content = str_replace('{{APACHE_YESTERDAY}}', (clone $now)->modify('-1 day')->format('D M d H:i:s.v Y'), $content);
    return $content;
}

$logFiles = glob($logDir . '*.log');
$logFiles = array_map('basename', $logFiles);

sort($logFiles);
if (empty($logFiles)) $error = 'No .log files found in logs/ directory.';

$selectedLog  = $_GET['logFile'] ?? ($logFiles[0] ?? '');
$language     = $_GET['language'] ?? 'en';
$outputFormat = $_GET['outputFormat'] ?? 'html';
$order        = $_GET['order'] ?? 'desc';
$pageNumber   = max(1, (int)($_GET['page'] ?? 1));
$pageSize     = (int)($_GET['pageSize'] ?? 0);
$theme        = $_GET['theme'] ?? 'peachy';

$userInput = '';
if ($selectedLog && file_exists($logDir . $selectedLog)) {
    $raw = file_get_contents($logDir . $selectedLog);
    if ($raw !== false) $userInput = replaceDatePlaceholders($raw);
}

$resultHtml = $resultJson = $parseError = '';
$totalEntries = 0;
if ($userInput !== '') {
    try {
        $log = new logFuse(['debug' => false]);
        $log->addFileContent($userInput)
            ->setLanguage($language)
            ->setOrder($order)
            ->setPagination($pageNumber, $pageSize);

        $totalEntries = $log->getTotalEntryCount();
        if ($outputFormat === 'html') $resultHtml = $log->getOutput('html');
        else $resultJson = $log->getOutput('json');
    } catch (Throwable $e) {
        $parseError = $e->getMessage();
    }
}

$logFuseCss = logFuse::getCss($theme);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>logFuse Playground – Log Parser & Formatter</title>
    <link rel="icon" type="image/png" href="../images/logo-xs.png">
    <link rel="stylesheet" href="sub/core.css">
    <link rel="stylesheet" href="sub/template.css">
    <link rel="stylesheet" href="sub/components.css">
    <link rel="stylesheet" href="sub/desktop.css">
    <style>
        <?= $logFuseCss ?>
        /* Theme-specific output background */
        <?php
        if ($outputFormat === 'html') {
            echo '
        .output-container.theme-e2 { background: #041e28; }
        .output-container.theme-dark { background: #202020; }
        .output-container.theme-light { background: #f8f8f8; }
        .output-container.theme-peachy { background: #f1e9e9; }
        ';
        } ?>
    </style>
    <script src="sub/script.js" defer></script>
    <script>
        // Additional script to disable theme select when output format is not HTML
        document.addEventListener('DOMContentLoaded', function() {
            const outputFormatSelect = document.getElementById('outputFormatSelect');
            const themeSelect        = document.getElementById('themeSelect');
            const languageSelect     = document.getElementById('languageSelect');

            function toggleThemeSelect() {
                if (!themeSelect) return;
                const isHtml = outputFormatSelect && outputFormatSelect.value === 'html';
                themeSelect.disabled = !isHtml;
                languageSelect.disabled = !isHtml;
            }

            if (outputFormatSelect) {
                outputFormatSelect.addEventListener('change', toggleThemeSelect);
                toggleThemeSelect(); // initial call
            }
        });
    </script>
</head>

<body>
    <a class="logo-container" href="./"><img src="../images/logo-m.png" alt="logFuse Logo" id="logo"></a>
    <h1>logFuse Playground</h1>

    <form method="get" id="mainForm" class="card sticky-input">
        <div class="card-body">
            <!-- All select boxes with labels on top, side by side -->
            <div class="toolbar-selects">
                <div class="field-group">
                    <label>📄 Log file</label>
                    <select name="logFile" id="logFileSelect">
                        <?php foreach ($logFiles as $file): ?>
                            <option value="<?= htmlspecialchars($file) ?>" <?= $selectedLog === $file ? 'selected' : '' ?>><?= htmlspecialchars($file) ?></option>
                        <?php endforeach; ?>
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
                    <select name="theme" id="themeSelect">
                        <option value="peachy" <?= $theme === 'peachy' ? 'selected' : '' ?>>Peachy</option>
                        <option value="light" <?= $theme === 'light' ? 'selected' : '' ?>>Light</option>
                        <option value="dark" <?= $theme === 'dark' ? 'selected' : '' ?>>Dark</option>
                        <option value="e2" <?= $theme === 'e2' ? 'selected' : '' ?>>e2 (original)</option>
                    </select>
                </div>
                <div class="field-group">
                    <label>🌍 Language (Date)</label>
                    <select name="language" id="languageSelect">
                        <option value="en" <?= $language === 'en' ? 'selected' : '' ?>>English</option>
                        <option value="de" <?= $language === 'de' ? 'selected' : '' ?>>Deutsch</option>
                        <option value="tr" <?= $language === 'tr' ? 'selected' : '' ?>>Türkçe</option>
                    </select>
                </div>
                <div class="field-group">
                    <label>🔄 Order</label>
                    <select name="order" id="orderSelect">
                        <option value="desc" <?= $order === 'desc' ? 'selected' : '' ?>>Newest first</option>
                        <option value="asc" <?= $order === 'asc' ? 'selected' : '' ?>>Oldest first</option>
                    </select>
                </div>

                <!-- Page and Page Size next to each other -->
                <div class="field-group">
                    <label>📄 Page-Number</label>
                    <input type="number" name="page" value="<?= $pageNumber ?>" min="1" class="page-input" />
                </div>
                <div class="field-group">
                    <label>Page-Size</label>
                    <select name="pageSize" id="pageSizeSelect">
                        <option value="0" <?= $pageSize == 0 ? 'selected' : '' ?>>All</option>
                        <option value="5" <?= $pageSize == 5 ? 'selected' : '' ?>>5</option>
                        <option value="10" <?= $pageSize == 10 ? 'selected' : '' ?>>10</option>
                        <option value="20" <?= $pageSize == 20 ? 'selected' : '' ?>>20</option>
                        <option value="50" <?= $pageSize == 50 ? 'selected' : '' ?>>50</option>
                    </select>
                </div>
            </div>

            <!-- Status line: left info, right "Ready" -->
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
            <h4>📝 Raw Log Content (readonly)</h4><textarea readonly rows="20" class="log-textarea" id="logDisplay"><?= htmlspecialchars($userInput) ?></textarea>
        </div>
        <div class="query-card">
            <h4>✨ Formatted Output</h4>
            <div class="output-container theme-<?= htmlspecialchars($theme) ?>">
                <?php if ($parseError): ?>
                    <div class="message error"><?= htmlspecialchars($parseError) ?></div>
                <?php elseif ($outputFormat === 'html' && $resultHtml): ?>
                    <?= $resultHtml ?>
                <?php elseif ($outputFormat === 'json' && $resultJson): ?>
                    <pre class="json-output"><?= htmlspecialchars($resultJson) ?></pre>
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
                <p><strong>Placeholders:</strong> Use <code>{{TODAY}}</code>, <code>{{YESTERDAY}}</code>, <code>{{DAYS_AGO:N}}</code>, <code>{{LAST_YEAR}}</code>, <code>{{APACHE_TODAY}}</code>, <code>{{APACHE_YESTERDAY}}</code> in your log files – they will be replaced with current dates.</p>
            </div>
        </div>
        <div class="card">
            <h3>🎨 CSS Customization</h3>
            <div class="scrollable">
                <p>The HTML output uses <code>.lf-*</code> classes. You can override CSS variables:</p>
                <pre>--lf-level-error: #e25950;\n--lf-level-info: #ff9f48;\n--lf-datetime-color: rgb(0,91,118);\n--lf-progress: 50%;</pre>
            </div>
        </div>
    </div>
</body>

</html>