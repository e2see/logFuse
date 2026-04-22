document.addEventListener('DOMContentLoaded', function() {

    const form = document.getElementById('mainForm');
    const statusSpan = document.getElementById('status');

    if (!form) return;

    const allFormElements = form.querySelectorAll('select, input');

    function submitForm() {
        statusSpan.textContent = '⏳ Loading...';
        form.submit();
    }

    allFormElements.forEach(function(el) {
        el.addEventListener('change', submitForm);
    });

    const pageInput = document.querySelector('input[name="page"]');
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
            if (window.scrollY > 100) {
                logo.classList.add('logo-hidden');
            } else {
                logo.classList.remove('logo-hidden');
            }
        });
    }
});