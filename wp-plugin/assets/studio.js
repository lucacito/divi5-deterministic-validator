/* JHMG AI Editor for Divi 5: Lab, the screen's behaviour.
 *
 * Every text from the server or the person is written with textContent or value, never as HTML. The only markup that
 * reaches the page is preview.html (already escaped by the server) through the preview frame's srcdoc. The pure logic
 * lives in studio-core.js (window.aiedLabCore). Requests go only to this site's admin-ajax.php with the nonce.
 */
(function () {
    'use strict';

    var cfg = window.aiedLabConfig;
    var core = window.aiedLabCore;
    var app = document.getElementById('aied-lab-app');
    if (!cfg || !core || !app) { return; }

    var t = cfg.i18n || {};
    var catalog = cfg.recipes || {};
    var f = core.format;
    var byId = function (id) { return document.getElementById(id); };
    var POLL_MS = 20000;
    var POLL_MAX = 30;

    var state = {
        goal: 'book', name: '', line: '', action: '', language: '', kit: '', density: 'balanced',
        sections: [], touched: false
    };
    var briefId = null;
    var synced = null;          // the brief as last known to be stored (sent by a save or create, or read back)
    var syncedKeys = [];        // the section key of each section of that brief
    var lastMap = [];           // state index of each brief section, from the last build
    var report = null;          // the last report (preview or create)
    var previewOk = null;       // true, false, or null when not checked
    var notes = {};             // state index => list of plain notes for that section
    var problems = { page: [], sections: {} };
    var step = 1;
    var previewTimer = null;
    var previewSeq = 0;
    var pollTimer = null;
    var polls = 0;
    var savedKey = null;        // the brief last saved or loaded, as core.briefKey; answers that differ are unsaved
    var gate = core.makeGate(onBusy);
    var WRITE_BUTTONS = ['aied-lab-rebuild', 'aied-lab-handoff', 'aied-lab-handoff-2', 'aied-lab-refresh'];

    // ── Small helpers ───────────────────────────────────────────────────

    function el(tag, attrs, text) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) { node.setAttribute(k, attrs[k] === true ? '' : String(attrs[k])); }
        });
        if (text !== undefined && text !== null) { node.textContent = String(text); }
        return node;
    }

    function clear(node) { while (node.firstChild) { node.removeChild(node.firstChild); } }

    function status(text) { byId('aied-lab-status').textContent = text || ''; }

    function roleLabel(role) { return (cfg.roles && cfg.roles[role]) || core.humanize(role); }

    function slotLabel(role) { return (cfg.slotRoles && cfg.slotRoles[role]) || core.humanize(role); }

    function sectionLabel(i) {
        var s = state.sections[i];
        var r = s && catalog[s.recipe] ? roleLabel(catalog[s.recipe].role) : core.humanize(s ? s.recipe : '');
        return f(t.sectionTitle, i + 1, r);
    }

    /** The string for one, or the string for more with the count put in. */
    /** The live section with this key (handlers look it up when they fire, never keep one of their own). */
    function sectionByKey(key) {
        var i = core.indexOfKey(state.sections, key);
        return i < 0 ? null : state.sections[i];
    }

    /** Redraws inside root and gives the keyboard focus (and the caret) back to the control with the same id. */
    function keepFocus(root, redraw) {
        var a = document.activeElement;
        var id = a && root.contains(a) ? a.id : '';
        var caret = id && typeof a.selectionStart === 'number' ? [a.selectionStart, a.selectionEnd] : null;
        redraw();
        var b = id ? byId(id) : null;
        if (!b) { return; }
        b.focus();
        if (caret && typeof b.setSelectionRange === 'function') {
            try { b.setSelectionRange(caret[0], caret[1]); } catch (e) { /* not a text control */ }
        }
    }

    /** While a write runs, its buttons are off; Create also stays off while the layout check failed. */
    function onBusy(busy) {
        WRITE_BUTTONS.forEach(function (id) { if (byId(id)) { byId(id).disabled = busy; } });
        if (byId('aied-lab-create')) { byId('aied-lab-create').disabled = busy || previewOk === false; }
        app.setAttribute('aria-busy', busy ? 'true' : 'false');
    }

    /** The answers now count as saved (b: the brief just sent; none: the form as it is). */
    function remember(b) {
        savedKey = b ? JSON.stringify(b) : core.briefKey(state, catalog, t.untitled);
    }

    /** The address gets &brief=<id> (no reload), so a reload or a bookmark reopens this brief. */
    function keepAddress() {
        if (briefId && window.history && typeof window.history.replaceState === 'function') {
            window.history.replaceState(null, '', core.briefUrl(window.location.href, briefId));
        }
    }

    function plural(n, one, many) { return n === 1 ? t[one] : f(t[many], n); }

    function layoutName(name) { return core.humanize(name); }

    function brief() {
        var built = core.buildBrief(state, catalog, t.untitled);
        lastMap = built.map;
        return built.brief;
    }

    function api(action, fields) {
        var body = new FormData();
        body.append('action', 'aied_studio_' + action);
        body.append('nonce', cfg.nonce);
        Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
        return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (res) {
                return res.json().then(function (json) {
                    return { ok: !!(json && json.success), status: res.status, data: (json && json.data) || {} };
                }, function () { return { ok: false, status: res.status, data: {} }; });
            }, function () { return { ok: false, status: 0, data: {} }; });
    }

    function failText(r) {
        var code = r.data && r.data.code;
        if (r.status === 422 && (code === 'INVALID_BRIEF' || code === 'LAYOUT_INVALID')) { return t.fixProblems; }
        return (r.data && typeof r.data.message === 'string' && r.data.message) ? r.data.message : t.requestFailed;
    }

    // ── Steps ───────────────────────────────────────────────────────────

    function goTo(n) {
        step = Math.max(1, Math.min(5, n));
        app.querySelectorAll('.aied-lab-panel').forEach(function (panel) {
            panel.hidden = String(step) !== panel.getAttribute('data-step');
        });
        app.querySelectorAll('.aied-lab-step').forEach(function (b) {
            if (b.getAttribute('data-goto') === String(step)) { b.setAttribute('aria-current', 'step'); } else { b.removeAttribute('aria-current'); }
        });
        if (step === 3) { renderSections(); }
        if (step === 4) { renderWords(); }
        if (step === 5) { renderReview(); }
        var heading = byId('aied-lab-h' + step);
        if (heading) { heading.focus(); }
    }

    // ── Step 1 and 2 controls ───────────────────────────────────────────

    function checkedValue(name) {
        var c = app.querySelector('input[name="' + name + '"]:checked');
        return c ? c.value : '';
    }

    function setChecked(name, value) {
        app.querySelectorAll('input[name="' + name + '"]').forEach(function (r) { r.checked = r.value === value; });
    }

    function languageKit(id) {
        var found = (cfg.languages || []).filter(function (l) { return l.id === id; })[0];
        return found ? found.kit : '';
    }

    function showKitSummary() {
        var id = state.kit || languageKit(state.language);
        var kit = (cfg.kits || []).filter(function (k) { return k.id === id; })[0];
        byId('aied-lab-kit-summary').textContent = kit ? kit.summary : '';
    }

    function stepOne() {
        state = core.applyStepOne(state, catalog);
        if (step === 4) { renderWords(); }
        schedulePreview();
    }

    function bindControls() {
        app.querySelectorAll('input[name="aied-lab-goal"]').forEach(function (r) {
            r.addEventListener('change', function () { state.goal = checkedValue('aied-lab-goal'); suggest(false); });
        });
        [['aied-lab-name', 'name'], ['aied-lab-line', 'line'], ['aied-lab-action', 'action']].forEach(function (pair) {
            byId(pair[0]).addEventListener('input', function (e) { state[pair[1]] = e.target.value; stepOne(); });
        });
        app.querySelectorAll('input[name="aied-lab-language"]').forEach(function (r) {
            r.addEventListener('change', function () { state.language = checkedValue('aied-lab-language'); showKitSummary(); schedulePreview(); });
        });
        byId('aied-lab-kit').addEventListener('change', function (e) { state.kit = e.target.value; showKitSummary(); schedulePreview(); });
        app.querySelectorAll('input[name="aied-lab-density"]').forEach(function (r) {
            r.addEventListener('change', function () { state.density = checkedValue('aied-lab-density'); suggest(false); });
        });
        app.querySelectorAll('[data-goto]').forEach(function (b) {
            b.addEventListener('click', function () { goTo(parseInt(b.getAttribute('data-goto'), 10)); });
        });
        byId('aied-lab-resuggest').addEventListener('click', function () {
            if (state.touched && !window.confirm(t.resuggestConfirm)) { return; }
            suggest(true);
        });
        app.querySelectorAll('.aied-lab-widths [data-width]').forEach(function (b) {
            b.addEventListener('click', function () {
                byId('aied-lab-frame-wrap').setAttribute('data-width', b.getAttribute('data-width'));
                app.querySelectorAll('.aied-lab-widths [data-width]').forEach(function (o) { o.setAttribute('aria-pressed', o === b ? 'true' : 'false'); });
                layoutFrame();
            });
        });
        byId('aied-lab-repreview').addEventListener('click', function () { preview(); });
        if (cfg.assistantReady) {
            // The hand-off exists only once its assistant tool does (plan 3); without it the buttons are not rendered.
            byId('aied-lab-handoff').addEventListener('click', handoff);
            byId('aied-lab-handoff-2').addEventListener('click', handoff);
            byId('aied-lab-refresh').addEventListener('click', function () { refresh(true); });
        }
        byId('aied-lab-create').addEventListener('click', create);
        byId('aied-lab-rebuild').addEventListener('click', rebuild);
    }

    // ── Step 3: sections ────────────────────────────────────────────────

    /** The suggested stack for the goal and density. Replaces the sections only when asked or when untouched. */
    function suggest(force) {
        if (state.touched && !force) {
            status(t.resuggestKept);
            schedulePreview();
            return;
        }
        api('stack', { goal: state.goal, density: state.density }).then(function (r) {
            var stack = r.ok && r.data.stack ? r.data.stack.map(function (s) { return s.recipe; }) : (cfg.stacks[state.goal] || []);
            state.sections = core.sectionsFromStack(stack.filter(function (name) { return catalog[name]; }));
            state.touched = false;
            notes = {};
            problems = { page: [], sections: {} };
            state = core.applyStepOne(state, catalog);
            renderSections();
            if (step === 4) { renderWords(); }
            schedulePreview();
        });
    }

    function touch() { state.touched = true; }

    function layoutSelect(i) {
        var s = state.sections[i];
        var id = 'aied-lab-layout-' + i;
        var wrap = el('div', { 'class': 'aied-lab-field' });
        wrap.appendChild(el('label', { 'for': id }, t.layout));
        var select = el('select', { id: id, 'aria-describedby': id + '-hint' });
        if (!catalog[s.recipe]) {
            select.appendChild(el('option', { value: '' }, t.pickReplacement));
            Object.keys(catalog).forEach(function (name) {
                var label = f(t.problemAt, roleLabel(catalog[name].role), layoutName(name));
                var n = catalog[name].items;
                select.appendChild(el('option', { value: name }, n === 0 ? label : n === 1 ? f(t.layoutOneItem, label) : f(t.layoutItems, label, n)));
            });
        } else {
            core.variants(s, catalog).forEach(function (v) {
                var text = v.current ? f(t.layoutCurrent, layoutName(v.recipe))
                    : !v.allowed ? f(t.layoutTooFew, layoutName(v.recipe), v.items, v.filled)
                        : v.items === 0 ? layoutName(v.recipe)
                            : v.items === 1 ? f(t.layoutOneItem, layoutName(v.recipe)) : f(t.layoutItems, layoutName(v.recipe), v.items);
                var opt = el('option', { value: v.recipe, disabled: !v.allowed }, text);
                opt.selected = v.current;
                select.appendChild(opt);
            });
        }
        var key = s.key;
        select.addEventListener('change', function () { changeLayout(key, select.value); });
        wrap.appendChild(select);
        wrap.appendChild(el('p', { id: id + '-hint', 'class': 'description' }, t.itemsHint));
        return wrap;
    }

    function changeLayout(key, recipe) {
        var i = core.indexOfKey(state.sections, key);
        if (i < 0 || !recipe || !catalog[recipe]) { return; }
        var old = state.sections[i];
        var r = core.swap(old, recipe, catalog);
        if (!catalog[old.recipe]) {
            r = { section: core.newSection(recipe), kept: [], dropped: [] };
            r.section.on = old.on;
            r.section.key = old.key;
        }
        var lost = r.dropped.map(function (id) { return slotLabelFor(old.recipe, id); });
        if (lost.length && !window.confirm(f(t.swapConfirm, lost.join(', ')))) {
            renderSections();
            return;
        }
        state.sections[i] = r.section;
        delete notes[i];
        delete problems.sections[i];
        touch();
        renderSections();
        status(lost.length ? f(t.swapDropped, lost.join(', ')) : t.swapDone);
        var again = byId('aied-lab-layout-' + i);
        if (again) { again.focus(); }
        schedulePreview();
    }

    function slotLabelFor(recipe, id) {
        var slot = (catalog[recipe] ? catalog[recipe].slots : []).filter(function (s) { return s.id === id; })[0];
        return slot ? slotLabel(slot.role) : id;
    }

    function renderSections() {
        var list = byId('aied-lab-sections');
        // A redraw after a preview must not take the keyboard focus away: it goes back to the control with the same id.
        keepFocus(list, function () { drawSections(list); });
    }

    function drawSections(list) {
        clear(list);
        state.sections.forEach(function (s, i) {
            var key = s.key;
            var li = el('li', { 'class': 'aied-lab-section' });
            if (!s.on) { li.classList.add('is-off'); }
            var head = el('div', { 'class': 'aied-lab-section__head' });
            head.appendChild(el('h3', {}, sectionLabel(i)));
            if (!s.on) { head.appendChild(el('span', { 'class': 'aied-lab-tag' }, t.leftOut)); }
            li.appendChild(head);

            var tools = el('div', { 'class': 'aied-lab-section__tools' });
            var include = el('label', { 'class': 'aied-lab-check' });
            var box = el('input', { type: 'checkbox', id: 'aied-lab-on-' + i });
            box.checked = s.on;
            box.addEventListener('change', function () {
                var live = sectionByKey(key);
                if (!live) { return; }
                live.on = box.checked;
                touch();
                renderSections();
                schedulePreview();
            });
            include.appendChild(box);
            include.appendChild(document.createTextNode(' ' + t.include));
            tools.appendChild(include);
            [[-1, t.moveUp, t.moveUpLabel], [1, t.moveDown, t.moveDownLabel]].forEach(function (m) {
                var b = el('button', { type: 'button', id: 'aied-lab-' + (m[0] < 0 ? 'up-' : 'down-') + i, 'aria-label': f(m[2], i + 1), disabled: (m[0] < 0 && i === 0) || (m[0] > 0 && i === state.sections.length - 1) }, m[1]);
                b.classList.add('button', 'button-small');
                b.addEventListener('click', function () { moveSection(key, m[0]); });
                tools.appendChild(b);
            });
            li.appendChild(tools);
            li.appendChild(layoutSelect(i));
            sectionNotes(i).forEach(function (n) { li.appendChild(el('p', { 'class': 'aied-lab-note' }, n)); });
            list.appendChild(li);
        });
    }

    function moveSection(key, dir) {
        var from = core.indexOfKey(state.sections, key);
        var to = from + dir;
        if (from < 0 || to < 0 || to >= state.sections.length) { return; }
        state.sections = core.move(state.sections, from, to);
        var n = notes[from];
        notes[from] = notes[to];
        notes[to] = n;
        problems = { page: problems.page, sections: {} };
        touch();
        renderSections();
        status(f(t.moved, to + 1));
        var buttons = byId('aied-lab-sections').children[to];
        var target = buttons ? buttons.querySelectorAll('button')[dir < 0 ? 0 : 1] : null;
        if (target && !target.disabled) { target.focus(); } else if (buttons) { buttons.querySelector('select').focus(); }
        schedulePreview();
    }

    /** Notes and problems of a section, as plain text lines. */
    function sectionNotes(i) {
        var out = (notes[i] || []).slice();
        (problems.sections[i] || []).forEach(function (p) {
            out.push(p.slot ? f(t.problemAt, slotLabelFor(state.sections[i].recipe, p.slot), p.message) : p.message);
        });
        return out;
    }

    // ── Step 4: words and pictures ──────────────────────────────────────

    function fieldId(i, slotId) { return 'aied-lab-f-' + i + '-' + slotId.replace(/[^A-Za-z0-9_-]/g, '-'); }

    function renderWords() {
        var root = byId('aied-lab-words');
        keepFocus(root, function () { drawWords(root); });
    }

    function drawWords(root) {
        clear(root);
        var pages = byId('aied-lab-pages');
        if (!pages.firstChild) {
            (cfg.pages || []).forEach(function (p) { var o = el('option', { value: p.path }); o.label = p.title; pages.appendChild(o); });
        }
        state.sections.forEach(function (s, i) {
            if (!s.on) { return; }
            var set = el('fieldset', { 'class': 'aied-lab-section-words' });
            set.appendChild(el('legend', {}, sectionLabel(i) + ' · ' + layoutName(s.recipe)));
            sectionNotes(i).forEach(function (n) { set.appendChild(el('p', { 'class': 'aied-lab-note' }, n)); });
            var counts = {};
            var slots = catalog[s.recipe] ? catalog[s.recipe].slots : [];
            slots.forEach(function (slot) { counts[slot.role] = (counts[slot.role] || 0) + 1; });
            var seen = {};
            slots.forEach(function (slot) {
                seen[slot.role] = (seen[slot.role] || 0) + 1;
                var label = counts[slot.role] > 1 ? f(t.numbered, slotLabel(slot.role), seen[slot.role]) : slotLabel(slot.role);
                set.appendChild(slot.kind === 'picture' ? pictureField(s.key, i, slot, label) : textField(s.key, i, slot, label));
            });
            root.appendChild(set);
        });
    }

    function slotProblem(i, slotId) {
        return (problems.sections[i] || []).filter(function (p) { return p.slot === slotId; }).map(function (p) { return p.message; }).join(' ');
    }

    function textField(key, i, slot, label) {
        var s = sectionByKey(key);
        var id = fieldId(i, slot.id);
        var wrap = el('div', { 'class': 'aied-lab-field' });
        wrap.appendChild(el('label', { 'for': id }, label));
        var hint = slot.kind === 'link' ? t.linkHint : slot.role === 'figure' ? t.figureHint : t.emptyKeepsSample;
        var input = slot.role === 'body'
            ? el('textarea', { id: id, rows: 3, 'class': 'large-text', 'aria-describedby': id + '-hint' })
            : el('input', { id: id, type: 'text', 'class': 'regular-text', 'aria-describedby': id + '-hint', list: slot.kind === 'link' ? 'aied-lab-pages' : null, inputmode: slot.role === 'figure' ? 'decimal' : null });
        if (slot.sample) { input.setAttribute('placeholder', slot.sample); }
        input.value = typeof s.slots[slot.id] === 'string' ? s.slots[slot.id] : '';
        input.addEventListener('input', function () {
            var live = sectionByKey(key);
            if (!live) { return; }
            if (input.value === '') { delete live.slots[slot.id]; } else { live.slots[slot.id] = input.value; }
            if (live.auto) { delete live.auto[slot.id]; }
            touch();
            schedulePreview();
        });
        wrap.appendChild(input);
        wrap.appendChild(el('p', { id: id + '-hint', 'class': 'description' }, hint));
        var problem = slotProblem(i, slot.id);
        if (problem) {
            input.setAttribute('aria-invalid', 'true');
            wrap.appendChild(el('p', { 'class': 'aied-lab-note' }, problem));
        }
        return wrap;
    }

    function pictureField(key, i, slot, label) {
        var s = sectionByKey(key);
        var id = fieldId(i, slot.id);
        var set = el('fieldset', { 'class': 'aied-lab-picture' });
        set.appendChild(el('legend', {}, label));
        var chosen = el('p', { 'class': 'aied-lab-chosen' });
        var thumb = el('img', { 'class': 'aied-lab-thumb', alt: '' });
        var current = s.pictures[slot.id] || null;
        var show = function () {
            var live = sectionByKey(key);
            var p = live ? live.pictures[slot.id] : null;
            var pack = p && p.pack ? (cfg.pack || []).filter(function (x) { return x.token === p.pack; })[0] : null;
            chosen.textContent = !p ? t.pictureSample : f(t.pictureChosen, pack ? pack.alt : p.url);
            // A thumbnail only for a built-in picture or a picture on this site (a path), never another site's address.
            var local = p && typeof p.url === 'string' && /^\/[^/]/.test(p.url) ? p.url : null;
            thumb.hidden = !(pack || local);
            if (pack) { thumb.src = pack.src; } else if (local) { thumb.src = local; }
        };
        var set2 = function (value) {
            var live = sectionByKey(key);
            if (!live) { return; }
            if (value) { live.pictures[slot.id] = value; } else { delete live.pictures[slot.id]; }
            touch();
            show();
            schedulePreview();
        };
        set.appendChild(chosen);
        set.appendChild(thumb);

        if (cfg.media && window.wp && window.wp.media) {
            var mb = el('button', { type: 'button' }, t.mediaButton);
            mb.classList.add('button');
            mb.addEventListener('click', function () { openMedia(key, slot, set2); });
            set.appendChild(mb);
        }

        var packId = id + '-pack';
        set.appendChild(el('label', { 'for': packId }, t.packLabel));
        var pack = el('select', { id: packId });
        pack.appendChild(el('option', { value: '' }, t.packNone));
        (cfg.pack || []).forEach(function (img) {
            var o = el('option', { value: img.token }, img.alt || img.token);
            o.selected = !!(current && current.pack === img.token);
            pack.appendChild(o);
        });
        pack.addEventListener('change', function () { urlInput.value = ''; set2(pack.value ? { pack: pack.value } : null); });
        set.appendChild(pack);

        var urlId = id + '-url';
        set.appendChild(el('label', { 'for': urlId }, t.urlLabel));
        var urlInput = el('input', { id: urlId, type: 'text', 'class': 'regular-text', 'aria-describedby': urlId + '-hint', inputmode: 'url' });
        urlInput.value = current && current.url ? current.url : '';
        urlInput.addEventListener('change', function () {
            var v = urlInput.value.trim();
            pack.value = '';
            set2(v ? { url: v } : null);
        });
        set.appendChild(urlInput);
        set.appendChild(el('p', { id: urlId + '-hint', 'class': 'description' }, t.urlHint));

        var reset = el('button', { type: 'button' }, t.clearPicture);
        reset.classList.add('button-link');
        reset.addEventListener('click', function () { pack.value = ''; urlInput.value = ''; set2(null); });
        set.appendChild(reset);
        var problem = slotProblem(i, slot.id);
        if (problem) { set.appendChild(el('p', { 'class': 'aied-lab-note' }, problem)); }
        show();
        return set;
    }

    /** The Media Library picker; an address on this site is stored as its path, so the brief accepts it on http too. */
    function openMedia(key, slot, apply) {
        var frame = window.wp.media({ title: t.mediaTitle, button: { text: t.mediaUse }, library: { type: 'image' }, multiple: false });
        frame.on('select', function () {
            var a = frame.state().get('selection').first().toJSON();
            var path = core.toSitePath(a.url, cfg.home);
            if (!path) { status(t.mediaNoAddress); return; }
            var altId = slot.id + '.alt';
            var s = sectionByKey(key);
            if (!s) { return; }
            if (a.alt && typeof a.alt === 'string' && !s.slots[altId] && (catalog[s.recipe].slots || []).some(function (x) { return x.id === altId; })) {
                s.slots[altId] = a.alt;
            }
            apply({ url: path });
            renderWords();
        });
        frame.open();
    }

    // ── Preview ─────────────────────────────────────────────────────────

    function schedulePreview() {
        window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(preview, 700);
    }

    function setFrame(preview) {
        var frame = byId('aied-lab-frame');
        if (preview && preview.mode === 'draft' && typeof preview.url === 'string' && core.sameSiteUrl(preview.url, window.location.href)) {
            frame.removeAttribute('srcdoc');
            frame.removeAttribute('sandbox');
            var url = preview.url + (preview.url.indexOf('?') >= 0 ? '&' : '?') + 'aied_v=' + previewSeq;
            frame.setAttribute('src', url);
            layoutFrame();
            return true;
        }
        if (preview && typeof preview.html === 'string') {
            frame.removeAttribute('src');
            frame.setAttribute('sandbox', '');
            frame.srcdoc = preview.html;
        }
        layoutFrame();
        return false;
    }

    /**
     * The frame at the true width of the chosen device (Desktop 1280, Tablet 768, Phone 390 CSS px), scaled down to fit
     * the pane, never up; the wrapper takes the scaled size so the unscaled box makes no overflow.
     */
    /** The preview draft was deleted (Create): the frame stops showing it, so nothing inside can load a missing page. */
    function clearFrame() {
        window.clearTimeout(previewTimer);
        previewSeq++;
        var frame = byId('aied-lab-frame');
        frame.removeAttribute('srcdoc');
        frame.removeAttribute('sandbox');
        frame.setAttribute('src', 'about:blank');
        byId('aied-lab-preview-state').textContent = t.previewRemoved;
    }

    function layoutFrame() {
        var wrap = byId('aied-lab-frame-wrap');
        var frame = byId('aied-lab-frame');
        var pane = wrap.parentNode ? wrap.parentNode.clientWidth - 2 : 0; // the wrapper's 1px border on each side
        var target = window.innerHeight * (window.innerWidth <= 782 ? 0.6 : 0.75);
        var box = core.frameBox(pane, wrap.getAttribute('data-width'), target);
        frame.style.width = box.width + 'px';
        frame.style.height = box.height + 'px';
        frame.style.transform = box.scale < 1 ? 'scale(' + box.scale + ')' : '';
        wrap.style.width = box.wrapWidth + 'px';
        wrap.style.height = box.wrapHeight + 'px';
    }

    function preview() {
        window.clearTimeout(previewTimer);
        var b = brief();
        var map = lastMap.slice();
        if (!b.sections.length) { status(t.noSections); return; }
        var seq = ++previewSeq;
        byId('aied-lab-preview-state').textContent = t.previewing;
        api('preview', { brief: JSON.stringify(b) }).then(function (r) {
            if (seq !== previewSeq) { return; }
            takeReport(r.data.report, map);
            if (!r.ok) {
                previewOk = r.status === 422 ? false : null;
                byId('aied-lab-preview-state').textContent = r.status === 422 ? t.previewFailed : failText(r);
                refreshViews();
                return;
            }
            previewOk = !(report && report.violations && report.violations.length);
            byId('aied-lab-preview-state').textContent = setFrame(r.data.preview) ? t.previewReady : t.previewApprox;
            refreshViews();
        });
    }

    function takeReport(r, map) {
        report = r && typeof r === 'object' ? r : null;
        report && (report.map = map);
        problems = core.problemsBySection(report ? report.problems : [], map);
    }

    function refreshViews() {
        if (step === 3) { renderSections(); }
        if (step === 5) { renderReview(); }
    }

    // ── Step 5: review ──────────────────────────────────────────────────

    function list(items) {
        var ul = el('ul');
        items.forEach(function (text) { ul.appendChild(el('li', {}, text)); });
        return ul;
    }

    function renderReview() {
        var verdict = byId('aied-lab-verdict');
        clear(verdict);
        verdict.className = 'aied-lab-verdict';
        if (previewOk === null) {
            verdict.appendChild(document.createTextNode(t.verdictUnknown));
        } else {
            verdict.classList.add(previewOk ? 'is-ok' : 'is-bad');
            verdict.appendChild(el('strong', {}, (previewOk ? t.okMark : t.badMark) + ': '));
            verdict.appendChild(document.createTextNode(previewOk ? t.verdictOk : t.verdictBad));
        }

        var box = byId('aied-lab-problems');
        clear(box);
        var lines = problems.page.map(function (p) { return f(t.problemAt, t.pageLevel, p.message); });
        Object.keys(problems.sections).forEach(function (i) {
            sectionNotes(parseInt(i, 10)).forEach(function (n) { lines.push(f(t.problemAt, sectionLabel(parseInt(i, 10)), n)); });
        });
        ((report && report.violations) || []).forEach(function (v) { lines.push(f(t.problemAt, t.pageLevel, v.message)); });
        if (lines.length) { box.appendChild(list(lines)); }

        var un = byId('aied-lab-unfilled');
        clear(un);
        var grouped = core.unfilledBySection(report ? report.unfilled : [], report ? report.map || [] : []);
        var keys = Object.keys(grouped);
        if (!report) {
            un.appendChild(el('p', {}, t.verdictUnknown));
        } else if (!keys.length) {
            un.appendChild(el('p', {}, t.unfilledNone));
        } else {
            un.appendChild(el('p', {}, plural(report.unfilled.length, 'unfilledOne', 'unfilledCount')));
            keys.forEach(function (i) {
                var counts = {};
                grouped[i].forEach(function (u) { counts[u.role] = (counts[u.role] || 0) + 1; });
                un.appendChild(el('h4', {}, sectionLabel(parseInt(i, 10))));
                un.appendChild(list(Object.keys(counts).map(function (role) { return counts[role] > 1 ? f(t.numbered, slotLabel(role), counts[role]) : slotLabel(role); })));
            });
        }
        var warnings = (report && report.warnings) || [];
        if (warnings.length) {
            un.appendChild(el('h4', {}, t.warningsTitle));
            un.appendChild(list(warnings.map(function (w) { return w.message; })));
        }
        byId('aied-lab-create').disabled = gate.busy() || previewOk === false;
    }

    // ── Save, hand-off, assistant words ─────────────────────────────────

    /**
     * Every write goes through here (controller ruling 19). With a stored brief, the stored version is read first and
     * merged, so words an assistant wrote are kept unless the person changed that field here; a failed read is told
     * plainly and nothing is sent. Resolves to { r, map } or null when nothing was sent.
     */
    function send(action, extra) {
        var read = briefId ? api('brief', { id: String(briefId) }) : Promise.resolve(null);
        return read.then(function (got) {
            if (got && !got.ok) { status(t.syncFailed); return null; }
            var stored = got ? (got.data.brief || {}) : null;
            var out = core.prepareSend(state, stored, synced, syncedKeys, catalog, t.untitled);
            state = out.state;
            if (stored) { synced = stored; }
            if (out.applied && step === 4) { renderWords(); }
            lastMap = out.map;
            var fields = { brief: JSON.stringify(out.brief) };
            Object.keys(extra || {}).forEach(function (k) { fields[k] = extra[k]; });
            if (briefId) { fields.id = String(briefId); }
            return api(action, fields).then(function (r) {
                if (r.ok) { synced = out.brief; syncedKeys = out.keys; }
                return { r: r, map: out.map, sent: out.brief };
            });
        });
    }

    function save() {
        status(t.saving);
        return send('save').then(function (x) {
            if (!x) { return false; }
            var r = x.r;
            if (!r.ok) {
                takeReport({ problems: r.data.problems || [] }, x.map);
                previewOk = r.status === 422 ? false : previewOk;
                status(failText(r));
                refreshViews();
                if (step === 4) { renderWords(); }
                return false;
            }
            briefId = r.data.id;
            remember(x.sent);
            keepAddress();
            return true;
        });
    }

    /** The clipboard was refused: the instruction box is on step 4, so the screen goes there and selects it. */
    function showInstruction() {
        goTo(4);
        byId('aied-lab-instruction-box').hidden = false;
        status(t.copyManual);
        byId('aied-lab-instruction').focus();
        byId('aied-lab-instruction').select();
    }

    function handoff() {
        gate.run(function () {
            return save().then(function (ok) {
                if (!ok) { return null; }
                return api('handoff', { id: String(briefId) }).then(function (r) {
                    if (!r.ok) { status(failText(r)); return null; }
                    var text = String(r.data.instruction || '');
                    byId('aied-lab-instruction').value = text;
                    startPoll();
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        return navigator.clipboard.writeText(text).then(function () { status(t.copied); }, showInstruction);
                    }
                    showInstruction();
                    return null;
                });
            });
        });
    }

    function startPoll() {
        if (!cfg.assistantReady) { return; }
        polls = 0;
        window.clearInterval(pollTimer);
        pollTimer = window.setInterval(function () {
            if (++polls > POLL_MAX) { window.clearInterval(pollTimer); return; }
            if (document.visibilityState === 'visible') { refresh(false); }
        }, POLL_MS);
    }

    /** Reads the stored brief and merges the words an assistant wrote. Quiet when it comes from the poll. */
    function refresh(loud) {
        if (!briefId) { if (loud) { status(t.needsSaveFirst); } return; }
        if (gate.busy()) { return; } // a write is running and merges the stored brief itself
        if (loud) { status(t.checking); }
        api('brief', { id: String(briefId) }).then(function (r) {
            if (!r.ok) { if (loud) { status(failText(r)); } return; }
            var stored = r.data.brief || {};
            var m = core.mergeAssistant(state, stored, synced, syncedKeys, catalog);
            state = m.state;
            synced = stored;
            if (m.applied) {
                if (step === 4) { renderWords(); }
                if (step === 3) { renderSections(); }
                schedulePreview();
            }
            if (loud || m.applied) {
                var text = m.applied ? plural(m.applied, 'mergedOne', 'mergedSome') : t.mergedNone;
                status(m.kept ? text + ' ' + plural(m.kept, 'mergedKeptOne', 'mergedKept') : text);
            }
        });
    }

    // ── Create and rebuild ──────────────────────────────────────────────

    function showCreated(message, editUrl) {
        byId('aied-lab-created').hidden = false;
        // A page exists for this brief now: Rebuild (and Open in Divi) take the place of Create.
        byId('aied-lab-create').hidden = true;
        byId('aied-lab-created-message').textContent = message;
        var open = byId('aied-lab-open');
        var safe = typeof editUrl === 'string' ? core.sameSiteUrl(editUrl, window.location.href) : null;
        open.hidden = !safe;
        if (safe) { open.setAttribute('href', safe); }
        status(message);
    }

    function create() {
        gate.run(function () {
            status(t.creating);
            return send('create').then(function (x) {
                if (!x) { return; }
                var r = x.r;
                takeReport(r.data.report, x.map);
                if (!r.ok) {
                    previewOk = r.status === 422 ? false : previewOk;
                    status(failText(r));
                    renderReview();
                    return;
                }
                briefId = r.data.brief_id;
                remember(x.sent);
                keepAddress();
                if (core.frameAfter('create', true) === 'clear') { clearFrame(); }
                window.clearInterval(pollTimer);
                showCreated((r.data.duplicate ? t.createdDuplicate : t.createdDraft) + unfilledNote(), r.data.edit_url);
                renderReview();
            });
        });
    }

    function unfilledNote() {
        var n = report && report.unfilled ? report.unfilled.length : 0;
        return n ? ' ' + plural(n, 'createdUnfilledOne', 'createdUnfilled') : '';
    }

    var REASONS = { edited: 'reasonEdited', published: 'reasonPublished', trashed: 'reasonTrashed', missing: 'reasonMissing', no_undo: 'reasonNoUndo', no_access: 'reasonNoAccess' };

    function rebuildText(data) {
        if (data.mode === 'overwritten') { return t.rebuiltOverwritten; }
        var key = REASONS[data.reason];
        return key ? f(t.rebuiltNewDraft, t[key]) : String(data.message || '');
    }

    function rebuild() {
        gate.run(function () {
            return save().then(function (ok) {
                if (!ok) { return null; }
                status(t.rebuilding);
                return api('rebuild', { id: String(briefId) }).then(function (r) {
                    if (!r.ok) {
                        if (r.data.code === 'RECIPE_REMOVED') { markGone(); }
                        status(failText(r));
                        return;
                    }
                    takeReport(r.data.report, lastMap.slice());
                    // Ruling 12: an overwrite keeps the page title, and the person is told so.
                    showCreated(rebuildText(r.data) + unfilledNote(), r.data.edit_url);
                    renderReview();
                });
            });
        });
    }

    /** Sections whose layout left the library get a note and a picker of replacement layouts. */
    function markGone() {
        state.sections.forEach(function (s, i) {
            if (!catalog[s.recipe]) { notes[i] = [t.recipeGone]; }
        });
        renderSections();
    }

    // ── Start ───────────────────────────────────────────────────────────

    function start() {
        state.name = byId('aied-lab-name').value;
        state.line = byId('aied-lab-line').value;
        state.goal = checkedValue('aied-lab-goal') || 'book';
        state.language = checkedValue('aied-lab-language');
        state.density = checkedValue('aied-lab-density') || 'balanced';
        if (cfg.brief && cfg.brief.brief) {
            var loaded = core.fromStored(cfg.brief.brief, catalog);
            ['name', 'goal', 'language', 'kit'].forEach(function (k) { if (loaded.state[k]) { state[k] = loaded.state[k]; } });
            state.sections = loaded.state.sections;
            state.touched = true;
            briefId = cfg.brief.id;
            synced = cfg.brief.brief;
            syncedKeys = state.sections.map(function (x) { return x.key; });
            loaded.gone.forEach(function (i) { notes[i] = [t.recipeGone]; });
            Object.keys(loaded.stale).forEach(function (i) {
                (notes[i] = notes[i] || []).push(f(t.slotsChanged, loaded.stale[i].join(', ')));
            });
            byId('aied-lab-name').value = state.name;
            setChecked('aied-lab-goal', state.goal);
            setChecked('aied-lab-language', state.language);
            byId('aied-lab-kit').value = state.kit;
            if (cfg.brief.page_id) { showCreated(t.reopened, cfg.brief.edit_url); }
            keepAddress();
        } else {
            state.kit = byId('aied-lab-kit').value;
            state.sections = core.sectionsFromStack((cfg.stacks[state.goal] || []).filter(function (n) { return catalog[n]; }));
            state = core.applyStepOne(state, catalog);
        }
        remember();
        window.addEventListener('beforeunload', function (e) {
            // Armed only while the answers differ from the brief last saved or loaded.
            if (!core.isDirty(state, savedKey, catalog, t.untitled)) { return undefined; }
            e.preventDefault();
            e.returnValue = t.leaveWarning;
            return t.leaveWarning;
        });
        showKitSummary();
        bindControls();
        app.hidden = false;
        layoutFrame();
        window.addEventListener('resize', layoutFrame);
        if (window.ResizeObserver) { new window.ResizeObserver(layoutFrame).observe(byId('aied-lab-frame-wrap').parentNode); }
        renderSections();
        preview();
    }

    start();
}());
