/* JHMG AI Editor for Divi 5 — Admin JS */
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

    // ── Confirm before a destructive form submit ───────────────────────
    // A form with data-confirm="message" asks first; Cancel stops the submit.

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.getAttribute) return;
        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
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
            llmTabs.forEach(function (t) {
                t.classList.remove('aied-llm-tab--active');
                t.setAttribute('aria-selected', 'false');
            });
            tab.classList.add('aied-llm-tab--active');
            tab.setAttribute('aria-selected', 'true');

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
        llmTabs.forEach(function (t) {
            t.setAttribute('aria-selected', t === activeTab ? 'true' : 'false');
        });
        var activeId = 'aied-panel-' + activeTab.dataset.target;
        document.querySelectorAll('.aied-llm-panel').forEach(function (panel) {
            panel.hidden = panel.id !== activeId;
        });
    }

    // ── Test connection ────────────────────────────────────────────────
    // Three probes run from this browser (the plugin itself may not make HTTP requests): the MCP address
    // without the key, with the key, and the tool list. Only small facts are posted to the server for the
    // plain-English verdict: never the key and never a response body.

    var cfg = window.aiedAdminConfig;
    var testWrap = document.getElementById('aied-test-connection');
    var testBtn = document.getElementById('aied-test-connection-btn');
    var testResult = document.getElementById('aied-test-result');
    var keyEl = document.getElementById('aied-api-key');

    if (!cfg || !testWrap || !testBtn || !testResult || !keyEl || typeof fetch !== 'function' || typeof AbortController === 'undefined') {
        return; // Without JavaScript the wrapper stays hidden.
    }
    testWrap.hidden = false;

    var PROBE_TIMEOUT_MS = 10000;

    function emptyProbe() {
        return { status: 0, contentType: '', redirected: false, jsonCode: '', jsonRpc: false, toolCount: null, headerHint: false, hasChallenge: false, docIssuer: '', docResource: '' };
    }

    // POSTs one JSON-RPC message to the MCP address and returns the observed facts. Never rejects.
    function probe(message, key) {
        var facts = emptyProbe();
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, PROBE_TIMEOUT_MS);
        var headers = { 'Content-Type': 'application/json' };
        if (key) {
            headers.Authorization = 'Bearer ' + key;
        }

        return fetch(cfg.mcpUrl, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify(message),
            credentials: 'omit',
            cache: 'no-store',
            redirect: 'follow',
            signal: controller.signal
        }).then(function (res) {
            facts.status = res.status;
            facts.contentType = (res.headers.get('content-type') || '').split(';')[0].trim().toLowerCase();
            facts.redirected = res.redirected === true;
            facts.hasChallenge = /resource_metadata=/.test(res.headers.get('www-authenticate') || '');
            return res.text().then(function (text) {
                var data = null;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    facts.headerHint = facts.contentType === 'text/html' || /^\s*</.test(text);
                }
                if (data && typeof data === 'object') {
                    if (typeof data.code === 'string') {
                        facts.jsonCode = data.code;
                    }
                    facts.jsonRpc = data.jsonrpc === '2.0';
                    if (data.result && Array.isArray(data.result.tools)) {
                        facts.toolCount = data.result.tools.length;
                    }
                }
                return facts;
            });
        }).catch(function () {
            return facts;
        }).then(function (done) {
            clearTimeout(timer);
            return done;
        });
    }

    // GETs one of the sign-in documents and notes only which site address it names.
    function docProbe(url) {
        var facts = emptyProbe();
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, PROBE_TIMEOUT_MS);

        return fetch(url, { method: 'GET', credentials: 'omit', cache: 'no-store', redirect: 'follow', signal: controller.signal })
            .then(function (res) {
                facts.status = res.status;
                facts.contentType = (res.headers.get('content-type') || '').split(';')[0].trim().toLowerCase();
                facts.redirected = res.redirected === true;
                return res.text().then(function (text) {
                    try {
                        var data = JSON.parse(text);
                        if (data && typeof data === 'object') {
                            facts.docIssuer = typeof data.issuer === 'string' ? data.issuer : '';
                            facts.docResource = typeof data.resource === 'string' ? data.resource : '';
                        }
                    } catch (e) {
                        facts.headerHint = facts.contentType === 'text/html' || /^\s*</.test(text);
                    }
                    return facts;
                });
            })
            .catch(function () { return facts; })
            .then(function (done) { clearTimeout(timer); return done; });
    }

    function runProbes(key) {
        var probes = { unauth: emptyProbe(), auth: emptyProbe(), tools: emptyProbe() };
        return probe({ jsonrpc: '2.0', id: 1, method: 'ping' }, '').then(function (a) {
            probes.unauth = a;
            if (a.status === 0) {
                return probes; // The address is unreachable: the other probes would only wait.
            }
            return probe({ jsonrpc: '2.0', id: 2, method: 'ping' }, key).then(function (b) {
                probes.auth = b;
                if (b.status === 0) {
                    return probes;
                }
                return probe({ jsonrpc: '2.0', id: 3, method: 'tools/list' }, key).then(function (c) {
                    probes.tools = c;
                    if (!cfg.oauth) {
                        return probes;
                    }
                    return docProbe(cfg.oauth.asUrl).then(function (d) {
                        probes.oauth_as = d;
                        return docProbe(cfg.oauth.prmUrl).then(function (e) {
                            probes.oauth_prm = e;
                            return probes;
                        });
                    });
                });
            });
        });
    }

    // Text nodes only: nothing from the server is ever parsed as HTML.
    function render(findings) {
        testResult.textContent = '';
        findings.forEach(function (f) {
            var box = document.createElement('div');
            box.className = 'aied-test-finding aied-test-finding--' + (['error', 'warning', 'ok'].indexOf(f.level) === -1 ? 'error' : f.level);
            var title = document.createElement('strong');
            title.textContent = f.title;
            var fix = document.createElement('p');
            fix.textContent = f.fix;
            box.appendChild(title);
            box.appendChild(fix);
            testResult.appendChild(box);
        });
        testResult.hidden = false;
    }

    function failure(title, fix) {
        render([{ level: 'error', title: title, fix: fix }]);
    }

    function diagnose(probes) {
        var body = new FormData();
        body.append('action', 'ai_editor_divi5_diagnose');
        body.append('nonce', cfg.nonce);
        body.append('probes', JSON.stringify(probes));

        return fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.success && data.data && Array.isArray(data.data.findings)) {
                    render(data.data.findings);
                } else {
                    failure(cfg.i18n.diagnoseFailed, cfg.i18n.tryAgain);
                }
            })
            .catch(function () {
                failure(cfg.i18n.diagnoseFailed, cfg.i18n.tryAgain);
            });
    }

    testBtn.addEventListener('click', function () {
        testBtn.disabled = true;
        testBtn.textContent = cfg.i18n.testing;
        testResult.hidden = true;
        testResult.setAttribute('aria-busy', 'true');

        runProbes(keyEl.dataset.key || '').then(diagnose).then(function () {
            testResult.removeAttribute('aria-busy');
            testBtn.disabled = false;
            testBtn.textContent = cfg.i18n.testAgain;
        });
    });

})();
