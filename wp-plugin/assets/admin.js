/* Divi 5 Validator — Admin JS */
(function () {
    'use strict';

    // ── Copy to clipboard ──────────────────────────────────────────────

    function copyText(text, btn) {
        navigator.clipboard.writeText(text).then(function () {
            var orig = btn.textContent;
            btn.textContent = 'Copied!';
            btn.classList.add('button-primary');
            setTimeout(function () {
                btn.textContent = orig;
            }, 2000);
        });
    }

    // Buttons with data-copy attribute (e.g. copy raw API key)
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            copyText(btn.dataset.copy, btn);
        });
    });

    // Buttons with data-target pointing to a <pre> element
    document.querySelectorAll('.divi5-copy-btn[data-target]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var el = document.getElementById(btn.dataset.target);
            if (el) copyText(el.textContent, btn);
        });
    });

    // ── Show / hide API key ────────────────────────────────────────────

    var toggleBtn = document.getElementById('divi5-toggle-key');
    var keyEl     = document.getElementById('divi5-api-key');

    if (toggleBtn && keyEl) {
        var revealed = false;
        toggleBtn.addEventListener('click', function () {
            revealed = !revealed;
            keyEl.textContent = revealed ? keyEl.dataset.key : '••••••••••••••••••••••••••••••••••••••••';
            keyEl.style.letterSpacing = revealed ? '0' : '2px';
            toggleBtn.textContent = revealed ? 'Hide' : 'Show';
        });
    }

    // ── LLM sub-tabs ──────────────────────────────────────────────────

    var llmTabs = document.querySelectorAll('.divi5-llm-tab');
    llmTabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var target = tab.dataset.target;

            // Update tab active state
            llmTabs.forEach(function (t) { t.classList.remove('divi5-llm-tab--active'); });
            tab.classList.add('divi5-llm-tab--active');

            // Show/hide panels
            document.querySelectorAll('.divi5-llm-panel').forEach(function (panel) {
                panel.hidden = panel.id !== 'divi5-panel-' + target;
            });
        });
    });

})();
