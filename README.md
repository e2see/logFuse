![logFuse Logo](images/logo-s.png)

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

# logFuse – because errors are beautiful

**Making mistakes is beautiful. Learning from them even more so.**  
logFuse turns your messy, screaming error logs into clean, structured output – **HTML** for humans, **JSON** for machines.

> ⚡ **Not just a pretty printer** – logFuse parses, groups, and structures your logs so you can use them anywhere: on screen, in APIs, or in your data pipeline.

---

<br><br>
◤◤◤ Quick Example

```php
use e2\logFuse;

$log = new logFuse();
$log->addFile('/var/log/apache/error.log')
    ->setLanguage('en')
    ->setOrder('desc')
    ->setPagination(1, 50);

echo $log->getOutput('html');   // beautiful, themed HTML
echo $log->getOutput('json');   // structured JSON for APIs
```

One class. Two outputs. Your choice.

<br><br>
◤◤◤ HTML MODE – for humans

When you need to see, understand, and debug – right in your browser.



```php
echo logFuse::getCss('dark');
```


```css
:root {
  --lf-rgb-level-error: 255, 80, 80;
  --lf-rgb-datetime-color: 0, 200, 180;
  --lf-rgb-bg-base: 20, 20, 30;
}
```


```html
<ul class="lf-list">
  <li class="lf-entry error">
    <div class="lf-header">...</div>
    <div class="lf-message">...</div>
    <div class="lf-stacktrace">...</div>
  </li>
</ul>
```


<br><br>
◤◤◤ JSON MODE – for machines
```json
[
  {
    "datetime": "2025-04-18 14:32:11",
    "level": "error",
    "message": "Uncaught Exception: PDOException...",
    "file": "/var/www/app.php",
    "line": 42,
    "stacktrace": [
      "#0 /var/www/db.php(23): PDO->prepare()",
      "#1 /var/www/index.php(12): require_once()"
    ]
  }
]
```



<br><br>
◤◤◤ Playground – learn by doing



<br><br>
◤◤◤ Installation
