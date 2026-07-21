/* AI Editor for Divi 5 — Admin JS */
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
    document.querySelectorAll('.aied-copy-btn[data-target]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var el = document.getElementById(btn.dataset.target);
            if (el) copyText(el.textContent, btn);
        });
    });

    // ── Show / hide API key ────────────────────────────────────────────

    var toggleBtn = document.getElementById('aied-toggle-key');
    var keyEl     = document.getElementById('aied-api-key');

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

    var llmTabs = document.querySelectorAll('.aied-llm-tab');
    llmTabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var target = tab.dataset.target;

            // Update tab active state
            llmTabs.forEach(function (t) { t.classList.remove('aied-llm-tab--active'); });
            tab.classList.add('aied-llm-tab--active');

            // Show/hide panels
            document.querySelectorAll('.aied-llm-panel').forEach(function (panel) {
                panel.hidden = panel.id !== 'aied-panel-' + target;
            });
        });
    });

    // Initialise: activate the first tab and hide inactive panels on load.
    // Progressive enhancement — with JS disabled, no panel is hidden, so all
    // panels render stacked and remain usable.
    if (llmTabs.length) {
        var activeTab = document.querySelector('.aied-llm-tab--active') || llmTabs[0];
        activeTab.classList.add('aied-llm-tab--active');
        var activeId = 'aied-panel-' + activeTab.dataset.target;
        document.querySelectorAll('.aied-llm-panel').forEach(function (panel) {
            panel.hidden = panel.id !== activeId;
        });
    }

})();
