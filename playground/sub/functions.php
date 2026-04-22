<?php

declare(strict_types=1);


/**
 * SINGLE POINT OF TRUTH - all magic seconds with their day offsets.
 */
const MAGIC_SECONDS = [
    '00' => 0,
    '01' => -1,
    '02' => -2,
    '03' => -3,
    '05' => -5,
    '07' => -7,
    '10' => -10,
    '15' => -15,
    '20' => -20,
    '30' => -30,
    '45' => -45,
    '60' => -60,
    '59' => -365,
];


/**
 * Replaces formatted Magic Dates in the final HTML output.
 * Uses the same logFuse instance to get the target formatted string.
 */
function replaceFormattedMagicDatesUsingLogFuse(string $html, \e2\logFuse $log, DateTimeZone $timezone): string
{
    $now = new DateTime('now', $timezone);
    $nowTimestamp = $now->getTimestamp();

    foreach (MAGIC_SECONDS as $sec => $offsetDays) {
        $magicDate = "2111-01-01 11:11:$sec";
        $originalFormatted = $log->formatDate($magicDate);

        $realTimestamp = $nowTimestamp + ($offsetDays * 86400);
        $targetFormatted = $log->formatDate($realTimestamp);

        $html = str_replace($originalFormatted, $targetFormatted, $html);
    }

    return $html;
}


/**
 * Replaces Magic Dates (2111-01-01 11:11:XX) with current dates (for preview only).
 */
function replaceMagicDates(string $content, ?DateTimeZone $timezone = null, ?DateTime $now = null): string
{
    if ($now === null) {
        $now = new DateTime('now', $timezone);
    } elseif ($timezone !== null) {
        $now->setTimezone($timezone);
    }

    $mapping = [];

    foreach (MAGIC_SECONDS as $sec => $offset) {
        $magicDate = "2111-01-01 11:11:$sec";

        if ($offset === 0) {
            $mapping[$magicDate] = $now->format('Y-m-d H:i:s');
        } else {
            $mapping[$magicDate] = (clone $now)->modify("$offset days")->format('Y-m-d H:i:s');
        }
    }

    return str_replace(array_keys($mapping), array_values($mapping), $content);
}


/**
 * Creates a demo SQLite database with standard 3-column structure.
 * IMPORTANT: Data is inserted in ASCENDING order (oldest first).
 * logFuse will reverse with setOrder('desc') to show newest first.
 */
function initLegacyDatabase(string $logDir): void
{
    $dbFile = $logDir . 'demo_logs.sqlite';
    if (file_exists($dbFile)) {
        return;
    }

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $db = new SQLite3($dbFile);

    // Standard 3-column table structure (like Monolog, Log4j, PSR-3)
    $db->exec("CREATE TABLE IF NOT EXISTS logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        log_time DATETIME,
        log_level VARCHAR(20),
        log_message TEXT
    )");

    // Demo data with Magic Dates (2111-01-01 11:11:XX)
    // IMPORTANT: Inserted in ASCENDING order by time (oldest first, newest last)
    // - Smallest second (00) = today (0) -> will be newest
    // - Largest second (59) = -365 days -> will be oldest
    // This way when logFuse reads top-to-bottom, oldest comes first.
    // Then setOrder('desc') will reverse and show newest first.
    $demoData = [
        ['2111-01-01 11:11:59', 'ERROR',   'Fatal: Allowed memory size exhausted'],
        ['2111-01-01 11:11:60', 'WARNING', 'SSL certificate expires in 14 days'],
        ['2111-01-01 11:11:45', 'INFO',    'Backup completed'],
        ['2111-01-01 11:11:30', 'ERROR',   'Redis connection refused'],
        ['2111-01-01 11:11:20', 'INFO',    'User logout'],
        ['2111-01-01 11:11:15', 'ERROR',   'Uncaught Exception: Division by zero'],
        ['2111-01-01 11:11:10', 'WARNING', 'Slow query detected: 5.2s'],
        ['2111-01-01 11:11:07', 'INFO',    'Cron job executed'],
        ['2111-01-01 11:11:05', 'ERROR',   'Payment gateway timeout'],
        ['2111-01-01 11:11:03', 'DEBUG',   'User 123 logged in'],
        ['2111-01-01 11:11:02', 'WARNING', 'Disk usage above 80%'],
        ['2111-01-01 11:11:01', 'ERROR',   'Database connection failed'],
        ['2111-01-01 11:11:00', 'INFO',    'Application started successfully'],
    ];

    $stmt = $db->prepare("INSERT INTO logs (log_time, log_level, log_message) VALUES (:time, :level, :message)");

    foreach ($demoData as $row) {
        $stmt->bindValue(':time',    $row[0], SQLITE3_TEXT);
        $stmt->bindValue(':level',   $row[1], SQLITE3_TEXT);
        $stmt->bindValue(':message', $row[2], SQLITE3_TEXT);
        $stmt->execute();
    }

    $db->close();
}


/**
 * Generates a text-based table preview from the legacy database.
 * Shows entries with REPLACED dates (so the user sees current data).
 * Order follows the same logic as logFuse (depends on $_GET['order']).
 */
function generateLegacyTablePreview(string $dbPath, string $table, int $limit = 20, ?DateTimeZone $timezone = null): string
{
    if (!file_exists($dbPath)) {
        return "Database file not found: $dbPath";
    }

    $db = new SQLite3($dbPath);

    $order = ($_GET['order'] ?? 'desc') === 'desc' ? 'DESC' : 'ASC';
    $result = $db->query("SELECT log_time, log_level, log_message FROM logs ORDER BY log_time $order LIMIT " . (int)$limit);

    if (!$result) {
        $db->close();
        return "Query failed.";
    }

    $rows = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }
    $db->close();

    if (empty($rows)) {
        return "No rows found.";
    }

    // KEINE Ersetzung der Magic Dates mehr! Zeige die rohen Daten.

    $output = "+---------------------+---------+----------------------------------+\n";
    $output .= "| log_time            | level   | message                          |\n";
    $output .= "+---------------------+---------+----------------------------------+\n";

    foreach ($rows as $row) {
        $time   = str_pad(substr($row['log_time'] ?? '', 0, 19), 19);
        $level  = str_pad(substr($row['log_level'] ?? '', 0, 7), 7);
        $msg    = substr($row['log_message'] ?? '', 0, 30);
        $output .= "| $time | $level | $msg |\n";
    }

    $output .= "+---------------------+---------+----------------------------------+\n";

    return $output;
}

/**
 * Renders the dropdown for log file selection.
 */
function renderLogFileDropdown(string $selectedLog, array $logFiles, string $logDir): string
{
    // Normal .log files
    $html = '<optgroup label="📄 Original log files (.log)">';
    foreach ($logFiles as $file) {
        $selected = ($selectedLog === $file) ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($file) . '" ' . $selected . '>' . htmlspecialchars($file) . '</option>';
    }
    $html .= '</optgroup>';

    // CSV files (addTabularSource demo)
    $csvFiles = glob($logDir . '*.csv');
    if (!empty($csvFiles)) {
        $html .= '<optgroup label="📊 CSV files (tabular source demo)">';
        foreach ($csvFiles as $csvPath) {
            $csvFile = basename($csvPath);
            $optionValue = 'csv:' . $csvFile;
            $selected = ($selectedLog === $optionValue) ? 'selected' : '';
            $html .= '<option value="' . htmlspecialchars($optionValue) . '" ' . $selected . '>📊 ' . htmlspecialchars($csvFile) . '</option>';
        }
        $html .= '</optgroup>';
    }

    // Legacy SQLite database (standard 3 columns)
    $legacyDbFile = $logDir . 'demo_logs.sqlite';
    if (file_exists($legacyDbFile)) {
        $html .= '<optgroup label="🗄️ Legacy DB (standard 3 columns: log_time, log_level, log_message)">';
        $html .= '<option value="legacy:logs/demo_logs.sqlite:logs" ' . ($selectedLog === "legacy:logs/demo_logs.sqlite:logs" ? 'selected' : '') . '>🗄️ demo_logs (13 entries)</option>';
        $html .= '</optgroup>';
    }

    return $html;
}


/**
 * Generates a text-based preview for CSV files.
 */
function generateCsvPreview(string $csvPath, int $limit = 20, ?DateTimeZone $timezone = null): string
{
    if (!file_exists($csvPath)) {
        return "CSV file not found: $csvPath";
    }

    $handle = fopen($csvPath, 'r');
    if (!$handle) {
        return "Cannot read CSV file: $csvPath";
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        return "Empty or invalid CSV file.";
    }

    $rows = [];
    $count = 0;
    while (($row = fgetcsv($handle)) !== false && $count < $limit) {
        $rows[] = array_combine($header, $row);
        $count++;
    }
    fclose($handle);

    if (empty($rows)) {
        return "No data rows found in CSV.";
    }

    foreach ($rows as &$row) {
        if (isset($row['datetime'])) {
            $row['datetime'] = replaceMagicDates($row['datetime'], $timezone);
        }
    }

    $colWidths = [];
    foreach ($header as $col) {
        $colWidths[$col] = strlen($col);
    }
    foreach ($rows as $row) {
        foreach ($header as $col) {
            $val = $row[$col] ?? '';
            $colWidths[$col] = max($colWidths[$col], strlen($val));
        }
    }

    $separator = '+';
    foreach ($header as $col) {
        $separator .= str_repeat('-', $colWidths[$col] + 2) . '+';
    }
    $separator .= "\n";

    $output = $separator;
    $output .= '|';
    foreach ($header as $col) {
        $output .= ' ' . str_pad($col, $colWidths[$col]) . ' |';
    }
    $output .= "\n" . $separator;

    foreach ($rows as $row) {
        $output .= '|';
        foreach ($header as $col) {
            $val = $row[$col] ?? '';
            $output .= ' ' . str_pad(substr($val, 0, $colWidths[$col]), $colWidths[$col]) . ' |';
        }
        $output .= "\n";
    }
    $output .= $separator;

    return $output;
}
