document.addEventListener('DOMContentLoaded', function() {

    const form               = document.getElementById('mainForm');
    const logFileSelect      = document.getElementById('logFileSelect');
    const outputFormatSelect = document.getElementById('outputFormatSelect');
    const languageSelect     = document.getElementById('languageSelect');
    const orderSelect        = document.getElementById('orderSelect');
    const themeSelect        = document.getElementById('themeSelect');   // new
    const pageSizeSelect     = document.getElementById('pageSizeSelect');
    const pageInput          = document.querySelector('input[name="page"]');
    const statusSpan         = document.getElementById('status');


    function submitForm() {
        if (!form) return;
        statusSpan.textContent = '⏳ Loading...';
        form.submit();
    }

    if (logFileSelect) logFileSelect.addEventListener('change', submitForm);
    if (outputFormatSelect) outputFormatSelect.addEventListener('change', submitForm);
    if (languageSelect) languageSelect.addEventListener('change', submitForm);
    if (orderSelect) orderSelect.addEventListener('change', submitForm);
    if (themeSelect) themeSelect.addEventListener('change', submitForm);   // new
    if (pageSizeSelect) pageSizeSelect.addEventListener('change', submitForm);
    if (pageInput) {
        let timeout;
        pageInput.addEventListener('input', function() {
            clearTimeout(timeout);
            timeout = setTimeout(submitForm, 500);
        });
    }

    const resultBox = document.querySelector('.output-container');
    if (resultBox && resultBox.innerHTML.trim() !== '' && !resultBox.querySelector('.message.error')) {
        resultBox.classList.add('blink-box');
        setTimeout(() => resultBox.classList.remove('blink-box'), 2000);
    }

    const logo = document.getElementById('logo');
    if (logo) {
        window.addEventListener('scroll', function() {
            if (window.scrollY > 100) logo.classList.add('logo-hidden');
            else logo.classList.remove('logo-hidden');
        });
    }
});