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
│ IMPORTANT NOTE ABOUT MAGIC DATES                                          │
└───────────────────────────────────────────────────────────────────────────┘

The sample log files contain special timestamps like:

   2111-01-01 11:11:00  (becomes today)
   2111-01-01 11:11:01  (becomes yesterday)
   2111-01-01 11:11:02  (becomes day before yesterday)
   ... up to 2111-01-01 11:11:59 (one year ago)

These are NOT a feature of the logFuse class itself. They are only used
in this playground to keep the demo logs "evergreen" – no matter when you
run the demo, the output will always show relative terms like "today",
"yesterday", etc.

If you want to test logFuse with your own log files, simply copy any .log
file (with real timestamps) into the logs/ folder. The playground will
process them without any magic replacement – you will see the real dates
as they are. The magic dates are only there to make the pre‑installed
samples never look outdated.

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

   logs/apache_error.log   – Apache error log with magic dates
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

If you want to use the magic date trick, put timestamps like
"2111-01-01 11:11:XX" in your logs – the playground will replace
them with relative dates in the HTML output. But for normal use,
just use real timestamps – logFuse will parse them as they are.

┌───────────────────────────────────────────────────────────────────────────┐
│ WHAT THE PLAYGROUND SHOWS                                                 │
└───────────────────────────────────────────────────────────────────────────┘

   Left pane:   Raw log content (read-only, with magic dates visible)
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

   • Magic dates not replaced?
     The replacement happens in the HTML output after logFuse has parsed.
     If you see "1. Jan 2111" instead of "today", verify that the seconds
     (XX) match the expected offsets (00=today, 01=yesterday, etc.).

┌───────────────────────────────────────────────────────────────────────────┐
│ LICENSE                                                                   │
└───────────────────────────────────────────────────────────────────────────┘

Same as logFuse – MIT. Free to use, modify, and share.

═══════════════════════════════════════════════════════════════════════════

                        HAVE FUN PARSING!  📄✨

═══════════════════════════════════════════════════════════════════════════