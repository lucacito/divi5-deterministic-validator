'use strict';
/*
 * The pure parts of the Lab (internal name Studio) live gate, scripts/verify-studio.cjs: the briefs it sends, the
 * text it expects to see, and the verdicts it draws from measurements. No requests, no DOM, no WordPress: tested by
 * scripts/test-verify-studio.cjs, which the Makefile target runs first.
 */

/** Every title the gate gives a page or a brief begins with this (the preview drafts have their own fixed title). */
const TITLE_PREFIX = 'aied studio ';

/** The preview draft's title prefix (StudioPreview::TITLE_PREFIX). */
const PREVIEW_PREFIX = 'Lab preview (temporary) ';

/** The virtual width of each preview device (StudioScreen::DEVICES). */
const DEVICE_WIDTH = { desktop: 1280, tablet: 768, phone: 390 };

/** At or below this browser width the steps and the preview stack (studio.css). */
const STACK_AT = 1100;

/**
 * The hostile text of Ruling 17: double quotes, backslashes, an ampersand, a tag, a dollar amount and a line break.
 * `tag` makes each copy findable on the rendered page.
 */
function nasty(tag) {
  return `Fish & Chips "best" C:\\shop\\menu <b>bold</b> costs $5\nsecond line ${tag}`;
}

/** A brief whose title starts with the gate's prefix. */
function titled(token, name) {
  return TITLE_PREFIX + token + ' ' + name;
}

/** A small brief that compiles on every site: three sections, sample copy elsewhere. */
function smallBrief(title, language, headline) {
  return {
    version: 1,
    title,
    goal: 'book',
    language: language || 'editorial',
    sections: [
      { recipe: 'hero-cta', slots: { 'heading.1': headline || 'A first headline', 'button.0.text': 'Book a call', 'button.0.link': '/contact/' } },
      { recipe: 'stats-counter-band', slots: { 'heading.1': 'Numbers', 'number-counter.0.number': '1,250' } },
      { recipe: 'cta-band', slots: { 'heading.0': 'Ready when you are' } },
    ],
  };
}

/**
 * The escaping brief (Ruling 17): the hostile text in a heading, a button label, a counter title and a text body, and a
 * legal counter number. `where` lists [section index, slot id, the text sent] for the checks.
 */
function escapingBrief(title, tag) {
  const t = (k) => nasty(tag + '-' + k);
  const sections = [
    { recipe: 'hero-cta', slots: { 'heading.1': t('heading'), 'button.0.text': t('button'), 'button.0.link': '/contact/' } },
    { recipe: 'stats-counter-band', slots: { 'heading.1': 'Numbers ' + tag, 'number-counter.0.number': '1,250', 'number-counter.0.title': t('counter'), 'text.0.body.0': t('body') } },
    { recipe: 'cta-band', slots: { 'heading.0': 'Ready ' + tag } },
  ];
  const where = [
    { field: 'heading', section: 0, slot: 'heading.1', text: t('heading') },
    { field: 'button label', section: 0, slot: 'button.0.text', text: t('button') },
    { field: 'counter title', section: 1, slot: 'number-counter.0.title', text: t('counter') },
    { field: 'text body', section: 1, slot: 'text.0.body.0', text: t('body') },
  ];
  return { brief: { version: 1, title, goal: 'book', language: 'editorial', sections }, where, number: '1,250' };
}

/** Runs of white space (a line break included) read as one space, as a browser shows them. */
const collapse = (s) => String(s).replace(/\s+/g, ' ').trim();

/**
 * Whether the page shows the typed text literally. `shown` is the element's textContent. Exact = byte equal; ok = equal
 * once white space is collapsed (HTML shows a line break as a space), with no entity text (&amp; or &#36;) visible.
 */
function literalShown(sent, shown) {
  if (typeof shown !== 'string') return { ok: false, exact: false, why: 'not found on the page' };
  const exact = shown === sent || shown.trim() === sent;
  const entity = /&(?:amp|lt|gt|quot|#0?39|#36|#123);/.test(shown) && !/&(?:amp|lt|gt|quot|#0?39|#36|#123);/.test(sent);
  const ok = !entity && collapse(shown) === collapse(sent);
  return { ok, exact, why: ok ? '' : entity ? 'an entity is shown as text' : 'the text differs' };
}

/** What a rebuild must do for each scenario of check F. */
const REBUILD_EXPECT = {
  untouched: { mode: 'overwritten', reason: null },
  edited: { mode: 'new_draft', reason: 'edited' },
  published: { mode: 'new_draft', reason: 'published' },
  trashed: { mode: 'new_draft', reason: 'trashed' },
  missing: { mode: 'new_draft', reason: 'missing' },
  no_access: { mode: 'new_draft', reason: 'no_access' },
  no_undo: { mode: 'new_draft', reason: 'no_undo' },
};

/** Compares a rebuild answer with the scenario's expectation; returns a list of problems (empty = PASS). */
function rebuildProblems(scenario, answer) {
  const want = REBUILD_EXPECT[scenario];
  if (!want) return ['unknown scenario ' + scenario];
  const out = [];
  if (!answer || answer.success !== true) return ['the rebuild was refused: ' + JSON.stringify(answer && answer.data ? answer.data.code : answer)];
  const d = answer.data || {};
  if (d.mode !== want.mode) out.push(`mode ${d.mode} (expected ${want.mode})`);
  if ((d.reason === undefined ? null : d.reason) !== want.reason) out.push(`reason ${d.reason} (expected ${want.reason})`);
  if (want.mode === 'new_draft' && !(Number.isInteger(d.page_id) && d.page_id > 0)) out.push('no new page id');
  return out;
}

/** Two refusals are the same when their HTTP status and their whole JSON body are equal. */
function sameRefusal(a, b) {
  return !!a && !!b && a.status === b.status && JSON.stringify(a.body) === JSON.stringify(b.body) && a.body && a.body.success === false;
}

/**
 * The verdict on one browser width. m = { vw, scrollWidth, layoutWidth, bodyWidth, form: {top,bottom,left,right}, preview: {...} }.
 * Above STACK_AT the layout fills #wpbody-content and the two columns sit side by side; at or below it they stack.
 */
function layoutProblems(m) {
  const out = [];
  if (m.scrollWidth > m.vw) out.push(`horizontal overflow ${m.scrollWidth - m.vw}px`);
  const stacked = m.preview.top >= m.form.bottom - 1;
  if (m.vw > STACK_AT) {
    if (Math.abs(m.layoutWidth - m.bodyWidth) > 1) out.push(`layout ${m.layoutWidth}px is not #wpbody-content ${m.bodyWidth}px`);
    if (stacked) out.push('the columns stack above ' + STACK_AT + 'px');
  } else if (!stacked) {
    out.push('the columns sit side by side at or below ' + STACK_AT + 'px');
  }
  return out;
}

/** The preview frame at one device. f = { innerWidth, scrollWidth, hamburger (visible?), desktopLinks (visible link count) } */
function frameProblems(device, f) {
  const out = [];
  if (f.innerWidth !== DEVICE_WIDTH[device]) out.push(`frame innerWidth ${f.innerWidth} (expected ${DEVICE_WIDTH[device]})`);
  if (f.scrollWidth > f.innerWidth) out.push(`page overflow ${f.scrollWidth - f.innerWidth}px inside the frame`);
  if (device === 'desktop' && f.hamburger) out.push('the mobile menu button shows on Desktop');
  if (device === 'desktop' && !(f.desktopLinks >= 2)) out.push('no desktop navigation visible');
  if (device === 'phone' && !f.hamburger) out.push('no mobile menu button on Phone');
  return out;
}

/** The PHP errors a run logged (lines of JSON [level, message, file, line]), split into this plugin's and the rest. */
function phpErrors(lines, pluginDir) {
  const ours = [];
  const others = [];
  for (const line of String(lines || '').split('\n')) {
    if (!line.trim()) continue;
    let e;
    try { e = JSON.parse(line); } catch (x) { others.push({ raw: line.slice(0, 200) }); continue; }
    const item = { level: e[0], message: String(e[1]).slice(0, 200), file: String(e[2]), line: e[3] };
    (item.file.includes(pluginDir) ? ours : others).push(item);
  }
  return { ours, others };
}

/** PHP warnings printed into a page (when display is on). */
function printedPhpErrors(html) {
  const m = String(html || '').match(/<b>(?:Warning|Notice|Deprecated|Fatal error|Parse error)<\/b>:[^<]{0,200}/g);
  return m || [];
}

/** The screen's configuration object (wp_localize_script output) from the admin page HTML, or null. */
function labConfig(html) {
  const m = String(html || '').match(/var aiedLabConfig = (\{.*?\});\s*\n/s);
  if (!m) return null;
  try { return JSON.parse(m[1]); } catch (e) { return null; }
}

/** The first place two strings differ, with a little context, for a report. */
function firstDifference(a, b) {
  a = String(a); b = String(b);
  if (a === b) return null;
  let i = 0;
  while (i < a.length && i < b.length && a[i] === b[i]) i++;
  return { at: i, lengthA: a.length, lengthB: b.length, a: a.slice(Math.max(0, i - 40), i + 80), b: b.slice(Math.max(0, i - 40), i + 80) };
}

/** Whether a Dashboard HTML has a recent-edits row for the page title whose source reads "Lab". */
function dashboardShowsLab(html, title) {
  const rows = String(html || '').split(/<tr[\s>]/).slice(1);
  const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  return rows.some((r) => r.includes('<strong>' + esc(title) + '</strong>') && /<code>Lab<\/code>/.test(r));
}

/** "N PASS, S SKIP, F FAIL" over the recorded checks ({ok, skip}); only a FAIL makes the run fail. */
function summarize(results) {
  const list = results || [];
  const skipped = list.filter((r) => r.skip).length;
  const failed = list.filter((r) => !r.ok && !r.skip).length;
  const passed = list.length - skipped - failed;
  return { text: `${passed} PASS, ${skipped} SKIP, ${failed} FAIL`, failed, skipped };
}

/** The failed admin-ajax requests of the removed-recipe screen: at least one refused preview (422) and nothing else. */
function refusedPreviewSeen(ajax) {
  const list = ajax || [];
  return list.length > 0 && list.includes('aied_studio_preview 422') && list.every((a) => a === 'aied_studio_preview 422');
}

/** A gate title of this run (or any run when token is null). */
function isGateTitle(title, token) {
  return typeof title === 'string' && title.startsWith(TITLE_PREFIX + (token ? token + ' ' : ''));
}

// ── The assistant round trip (section I) and the hand-off (section G) ──

/** The size bound of a lab_build catalog answer in bytes (measured 4,031 on the 75-recipe library; twice that is the alarm). */
const CATALOG_MAX_BYTES = 8000;

const HASH = /^[0-9a-f]{64}$/;

/** A brief the assistant fills: the recipes of the escaping brief, every slot empty (so every slot is "unfilled"). */
function roundTripBrief(title) {
  return {
    version: 1,
    title,
    goal: 'book',
    language: 'editorial',
    sections: [
      { recipe: 'hero-cta', slots: {} },
      { recipe: 'stats-counter-band', slots: {} },
      { recipe: 'cta-band', slots: {} },
    ],
  };
}

/**
 * The words the assistant sends in one fill: the hostile text of Ruling 17 in a heading, a button label, a counter title and
 * a text body, and a legal counter number. `where` lists them for the literal checks, in the shape the escaping brief uses.
 */
function assistantWords(tag) {
  const t = (k) => nasty(tag + '-' + k);
  const where = [
    { field: 'heading', section: 0, slot: 'heading.1', text: t('heading') },
    { field: 'button label', section: 0, slot: 'button.0.text', text: t('button') },
    { field: 'counter title', section: 1, slot: 'number-counter.0.title', text: t('counter') },
    { field: 'text body', section: 1, slot: 'text.0.body.0', text: t('body') },
  ];
  const number = '1,250';
  const sections = [
    { section: 0, slots: { 'heading.1': where[0].text, 'button.0.text': where[1].text } },
    { section: 1, slots: { 'number-counter.0.number': number, 'number-counter.0.title': where[2].text, 'text.0.body.0': where[3].text } },
  ];
  return { sections, where, number };
}

/** Problems with a lab_build `catalog` answer: what the assistant needs to choose, and a size bound (raw JSON bytes). */
function catalogProblems(body, bytes) {
  if (!body || typeof body !== 'object') return ['no catalog answer'];
  const out = [];
  if (!Array.isArray(body.goals) || body.goals.length === 0) out.push('no goals');
  if (!Array.isArray(body.languages) || body.languages.length === 0 || !body.languages.every((l) => l && typeof l.id === 'string' && l.id !== '')) out.push('languages without ids');
  if (!Array.isArray(body.kits)) out.push('no kits');
  if (!body.recipes || typeof body.recipes !== 'object' || Object.keys(body.recipes).length === 0) out.push('no recipes by role');
  if (typeof body.how !== 'string' || body.how === '') out.push('no how text');
  if (!(bytes <= CATALOG_MAX_BYTES)) out.push(`the answer is ${bytes} bytes (bound ${CATALOG_MAX_BYTES})`);
  return out;
}

/** The slot entry of an `open` view, or null. */
function slotOf(open, section, id) {
  const sec = open && Array.isArray(open.sections) ? open.sections.find((s) => s.section === section) : null;
  return sec && Array.isArray(sec.slots) ? (sec.slots.find((x) => x.id === id) || null) : null;
}

/** Problems with an `open` answer against what the gate knows: { briefId, hash (the stored brief_hash), sections }. */
function openProblems(open, want) {
  if (!open || typeof open !== 'object') return ['no open answer'];
  const out = [];
  if (open.brief_id !== want.briefId) out.push(`brief_id ${open.brief_id} (expected ${want.briefId})`);
  if (!HASH.test(String(open.base_hash)) || open.base_hash !== want.hash) out.push('base_hash is not the stored hash');
  if (!Array.isArray(open.sections) || open.sections.length !== want.sections) out.push(`sections ${Array.isArray(open.sections) ? open.sections.length : 'missing'} (expected ${want.sections})`);
  else {
    const empty = open.sections.reduce((n, s) => n + (s.slots || []).filter((x) => !x.filled).length, 0);
    if (open.unfilled_count !== empty) out.push(`unfilled_count ${open.unfilled_count} (the empty slots are ${empty})`);
    if (open.sections.some((s) => !Array.isArray(s.slots) || s.slots.length === 0)) out.push('a section lists no slots');
  }
  if (typeof open.rules !== 'string' || open.rules === '') out.push('no rules text');
  return out;
}

/** Problems with a successful fill answer: every sent id applied, none ignored, the hash moved. want = { sent, oldHash }. */
function fillProblems(answer, want) {
  if (!answer || typeof answer !== 'object') return ['no fill answer'];
  const out = [];
  if (!Array.isArray(answer.applied) || answer.applied.length !== want.sent) out.push(`applied ${Array.isArray(answer.applied) ? answer.applied.length : 'missing'} slot(s) (sent ${want.sent})`);
  if (!Array.isArray(answer.ignored) || answer.ignored.length !== 0) out.push('some slots were ignored: ' + JSON.stringify(answer.ignored));
  if (!HASH.test(String(answer.base_hash)) || answer.base_hash === want.oldHash) out.push('the base_hash did not change');
  return out;
}

/** The words an `open` view holds against the words sent (exact, byte for byte); one problem per field that differs. */
function storedWordsProblems(open, words) {
  const out = [];
  for (const w of words.where) {
    const slot = slotOf(open, w.section, w.slot);
    if (!slot || slot.value !== w.text) out.push(`${w.field}: stored ${JSON.stringify(slot ? slot.value : null).slice(0, 80)} differs from what was sent`);
  }
  const num = slotOf(open, 1, 'number-counter.0.number');
  if (!num || num.value !== words.number) out.push('counter number differs');
  return out;
}

/** A fill with an OLD base_hash through the tool: isError, code CONFLICT, the current hash, no JSON-RPC error. */
function staleFillProblems(res, currentHash) {
  if (!res) return ['no answer'];
  const out = [];
  if (res.error) out.push('a JSON-RPC error instead of a tool result');
  if (res.isError !== true) out.push('not marked isError');
  const r = res.result || {};
  if (r.code !== 'CONFLICT') out.push('code ' + r.code + ' (expected CONFLICT)');
  if (r.current_base_hash !== currentHash) out.push('current_base_hash is not the stored hash');
  if (typeof r.message !== 'string' || r.message === '') out.push('no message');
  return out;
}

/** A screen-style save with an OLD base_hash through admin-ajax: HTTP 409, CONFLICT, the message, the CURRENT hash and brief. */
function staleSaveProblems(ajax, currentHash) {
  if (!ajax) return ['no answer'];
  const out = [];
  const d = ajax.body && ajax.body.data ? ajax.body.data : {};
  if (ajax.status !== 409) out.push('HTTP ' + ajax.status + ' (expected 409)');
  if (!ajax.body || ajax.body.success !== false || d.code !== 'CONFLICT') out.push('not a CONFLICT refusal');
  if (typeof d.message !== 'string' || d.message === '') out.push('no conflict message');
  if (d.hash !== currentHash) out.push('the answer does not carry the current hash');
  if (!d.brief || typeof d.brief !== 'object') out.push('the answer does not carry the current brief');
  return out;
}

/** Two tool refusals (another user's id, a missing id): both isError, equal results, and nothing but the message in them. */
function toolRefusalProblems(a, b) {
  if (!a || !b) return ['an answer is missing'];
  const out = [];
  for (const [n, x] of [['first', a], ['second', b]]) {
    if (x.isError !== true) out.push(`the ${n} answer is not a refusal`);
    else if (!x.result || Object.keys(x.result).join() !== 'error') out.push(`the ${n} refusal carries more than its message`);
  }
  if (JSON.stringify(a.result) !== JSON.stringify(b.result) || a.http !== b.http || JSON.stringify(a.error) !== JSON.stringify(b.error)) out.push('the two refusals differ');
  return out;
}

/** The instruction the hand-off copies: names the tool lab_build and the brief id as a whole number (#42 is not #420). */
function instructionProblems(text, briefId) {
  if (typeof text !== 'string' || text === '') return ['no instruction'];
  const out = [];
  if (!/\blab_build\b/.test(text)) out.push('does not name lab_build');
  if (!new RegExp('#' + briefId + '(?![0-9])').test(text)) out.push('does not name brief #' + briefId);
  return out;
}

const HANDOFF_IDS = ['aied-lab-handoff', 'aied-lab-refresh', 'aied-lab-instruction-box', 'aied-lab-handoff-2'];

/** The hand-off box of the screen when the tool exists: ready, all four elements, the box hidden until needed, a read-only textarea. */
function handoffUiProblems(ui) {
  const out = [];
  // wp_localize_script turns a boolean into the string "1" (and false into ""), so truthiness is the test.
  if (!ui || !ui.ready || ui.ready === '0') out.push('the screen does not say the assistant is ready');
  const missing = HANDOFF_IDS.filter((id) => !(ui && ui.present || []).includes(id));
  if (missing.length) out.push('missing: ' + missing.join(', '));
  if (ui && ui.boxHidden !== true) out.push('the instruction box is open before it is needed');
  if (ui && ui.textareaReadonly !== true) out.push('the instruction text is editable');
  return out;
}

/**
 * What a text field on the screen holds for a text: an <input type=text> drops line breaks (the browser's value
 * sanitization), a <textarea> keeps them. `tag` is INPUT or TEXTAREA.
 */
function fieldValueFor(text, tag) {
  return tag === 'TEXTAREA' ? text : String(text).replace(/[\r\n]/g, '');
}

/** The screen's fields against the words sent. observed = [{ field, tag, value }] in the order of `where`. */
function fieldProblems(where, observed) {
  const out = [];
  where.forEach((w, i) => {
    const o = observed[i];
    if (!o || o.value !== fieldValueFor(w.text, o.tag)) out.push(`${w.field}: the field shows ${JSON.stringify(o ? o.value : null).slice(0, 80)}`);
  });
  return out;
}

module.exports = {
  TITLE_PREFIX, PREVIEW_PREFIX, DEVICE_WIDTH, STACK_AT, REBUILD_EXPECT,
  nasty, titled, smallBrief, escapingBrief, literalShown, rebuildProblems, sameRefusal, layoutProblems, frameProblems,
  CATALOG_MAX_BYTES, HANDOFF_IDS, roundTripBrief, assistantWords, catalogProblems, slotOf, openProblems, fillProblems, storedWordsProblems, staleFillProblems, staleSaveProblems, toolRefusalProblems, instructionProblems, handoffUiProblems, fieldValueFor, fieldProblems,
  phpErrors, printedPhpErrors, summarize, refusedPreviewSeen, labConfig, firstDifference, dashboardShowsLab, isGateTitle, collapse,
};
