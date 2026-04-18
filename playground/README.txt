╔═══════════════════════════════════════════════════════════════════════════╗
║                            logFuse Playground                              ║
║                     Log Parser & Formatter – Live Demo                     ║
╚═══════════════════════════════════════════════════════════════════════════╝

┌───────────────────────────────────────────────────────────────────────────┐
│ WHAT IS THIS?                                                             │
└───────────────────────────────────────────────────────────────────────────┘

This is an interactive demo for logFuse – a PHP log parser that turns
raw log files into clean HTML (with themes) or structured JSON.

You can:
   • Select different log samples (Apache, PHP, MySQL, custom)
   • Switch between HTML and JSON output
   • Change themes (peachy, light, dark, e2)
   • Apply pagination and sorting
   • See the parsed output instantly

┌───────────────────────────────────────────────────────────────────────────┐
│ IMPORTANT NOTE ABOUT PLACEHOLDERS                                         │
└───────────────────────────────────────────────────────────────────────────┘

The sample log files contain special placeholders like:
   {{TODAY}}, {{YESTERDAY}}, {{DAYS_AGO:N}}, {{LAST_YEAR}},
   {{APACHE_TODAY}}, {{APACHE_YESTERDAY}}

These are NOT features of the logFuse class itself.
They are only used in this playground to keep the example logs
fresh and relevant – every time you load a file, the placeholders
are replaced with actual dates.

The real logFuse class works with real timestamps only.


┌───────────────────────────────────────────────────────────────────────────┐
│ QUICK START                                                               │
└───────────────────────────────────────────────────────────────────────────┘

   1. Place the logFuse files
      Make sure the parent directory contains src/logFuse.php

   2. Open in browser
      Point your web server to this folder and open index.php

   3. Pick a log file
      Choose from the dropdown – output appears automatically

   4. Experiment
      Change output format, theme, language, order, pagination


┌───────────────────────────────────────────────────────────────────────────┐
│ SAMPLE LOG FILES (included)                                               │
└───────────────────────────────────────────────────────────────────────────┘

   logs/apache_error.log   – Apache error log with placeholders
   logs/php_error.log      – PHP error log with stack traces
   logs/mysql_error.log    – MySQL/MariaDB server log
   logs/custom_app.log     – Custom application log (bracket style)
   logs/wild.log           – Mixed formats for stress testing


┌───────────────────────────────────────────────────────────────────────────┐
│ USE YOUR OWN LOG FILES                                                    │
└───────────────────────────────────────────────────────────────────────────┘

   • Copy any .log file into the logs/ folder
   • It will appear automatically in the file dropdown
   • No need to restart or change any code
   • The file must have a .log extension

All placeholders are replaced dynamically when you select a file.


┌───────────────────────────────────────────────────────────────────────────┐
│ WHAT THE PLAYGROUND SHOWS                                                 │
└───────────────────────────────────────────────────────────────────────────┘

   Left pane:   Raw log content (read-only, with placeholders replaced)
   Right pane:  Parsed output – either:
                • HTML: coloured, themed, with stack traces and file info
                • JSON: structured data ready for APIs or databases

┌───────────────────────────────────────────────────────────────────────────┐
│ HOW TO USE THE REAL logFuse CLASS                                         │
└───────────────────────────────────────────────────────────────────────────┘

   use e2\logFuse;

   $log = new logFuse();
   $log->addFile('/path/to/your.log')
       ->setLanguage('en')
       ->setOrder('desc')
       ->setPagination(1, 20);

   echo $log->getOutput('html');  // or 'json'

Full documentation is in src/logFuse.php (class DocBlock).


┌───────────────────────────────────────────────────────────────────────────┐
│ TROUBLESHOOTING                                                           │
└───────────────────────────────────────────────────────────────────────────┘

   • No log files shown?
     Make sure the logs/ folder exists and contains .log files – the five
     samples are included, and you can add more.

   • Parse errors?
     Check that the log format is supported – or add your own regex pattern
     with addPattern().

   • Placeholders not replaced?
     The replacement happens in index.php, not in the class. If you edit the
     sample files, keep the {{...}} syntax exactly as shown.


┌───────────────────────────────────────────────────────────────────────────┐
│ LICENSE                                                                   │
└───────────────────────────────────────────────────────────────────────────┘

Same as logFuse – MIT. Free to use, modify, and share.

═══════════════════════════════════════════════════════════════════════════

                        HAVE FUN PARSING!  📄✨

═══════════════════════════════════════════════════════════════════════════