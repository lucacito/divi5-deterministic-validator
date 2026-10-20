'use strict';
/*
 * The form half of scripts/verify-kits.cjs for ONE opened page at ONE width, kept apart (like kit-card-run.cjs and kit-item-run.cjs) so a real
 * Chromium can run it against a synthetic page without the dev site (scripts/test-kit-card-run.cjs). It takes a Playwright `page` showing the kit
 * sheet and, for each form of the sheet (K.FORM_SLOTS: one on the bg band, one on the surface band; two fields, the basic captcha and a submit
 * each), measures the form at rest (the fields' fill, frame, radius, ink, padding and `::placeholder` colour, the captcha ink, the form's own
 * container), samples the PAINTED fill under every field and the painted surface behind every field, the submit button and the captcha (the
 * element hidden, the WordPress admin bar and every fixed or sticky element hidden too), hovers the submit button with the real pointer and
 * measures again, then lets the pure comparisons of scripts/lib/kit-checks.cjs judge. It only reads the page (everything hidden for a sample is
 * restored, the placeholder style it injects is removed).
 */
const K = require('./kit-checks.cjs');
const { transitionMs } = require('./kit-card-run.cjs');

const slotArgs = () => K.FORM_SLOTS.map((s) => ({ key: s.key, submit: s.submit }));

/**
 * The painted fills of every measured form: { fills: per form, per field, the mean colour of the field's inner box with its placeholder hidden;
 * surfaces: per form { fields: the mean colour behind each field, submit: behind the submit button, captcha: behind the captcha row } } (null
 * where a sample failed). Fixed and sticky elements are hidden first and restored at the end; so is everything hidden for the surface samples.
 */
async function paintForms(p, measured) {
  await p.evaluate(() => {
    document.querySelectorAll('body *').forEach((n) => {
      const pos = getComputedStyle(n).position;
      if (pos === 'fixed' || pos === 'sticky') { n.dataset.aiedXf = n.style.visibility; n.style.visibility = 'hidden'; }
    });
    const st = document.createElement('style');
    st.id = 'aied-form-paint';
    st.textContent = '.et-l--post input::placeholder,.et-l--post textarea::placeholder{color:transparent !important;opacity:0 !important}.et-l--post input,.et-l--post textarea,.et-l--post .et_pb_contact_submit{transition:none !important;caret-color:transparent !important}';
    document.head.appendChild(st);
  });
  await p.waitForTimeout(50);
  const grab = async (clip) => {
    try { return K.pixelStats(K.decodePng(await p.screenshot({ clip, fullPage: true }))).mean; } catch (e) { return null; }
  };
  const wholeBox = (r) => ({ x: Math.max(0, Math.round(r.left)), y: Math.max(0, Math.round(r.top)), width: Math.max(1, Math.round(r.width)), height: Math.max(1, Math.round(r.height)) });
  const fills = [];
  for (const m of measured) {
    const row = [];
    for (const f of m.found ? m.fields : []) row.push(await grab(K.cardSampleClip(f.rect, f.el)));
    fills.push(row);
  }
  // The surfaces: the fields, the submit button and the captcha row hidden, the box each occupied sampled (what shows through is the form's own band).
  await p.evaluate(() => {
    document.querySelectorAll('[data-aied-ff],[data-aied-fs],[data-aied-fc]').forEach((n) => { n.dataset.aiedXv = n.style.visibility; n.style.visibility = 'hidden'; });
  });
  await p.waitForTimeout(50);
  const surfaces = [];
  for (const m of measured) {
    if (!m.found) { surfaces.push({ fields: [], submit: null, captcha: null }); continue; }
    const fieldRows = [];
    for (const f of m.fields) fieldRows.push(await grab(wholeBox(f.rect)));
    surfaces.push({ fields: fieldRows, submit: await grab(wholeBox(m.submit.rect)), captcha: m.captcha && m.captcha.found && m.captcha.rect ? await grab(wholeBox(m.captcha.rect)) : null });
  }
  await p.evaluate(() => {
    document.querySelectorAll('[data-aied-xv]').forEach((n) => { n.style.visibility = n.dataset.aiedXv || ''; delete n.dataset.aiedXv; });
    document.querySelectorAll('[data-aied-xf]').forEach((n) => { n.style.visibility = n.dataset.aiedXf || ''; delete n.dataset.aiedXf; });
    const st = document.getElementById('aied-form-paint');
    if (st) st.remove();
  });
  await p.waitForTimeout(50);
  return { fills, surfaces };
}

/**
 * @param {import('playwright').Page} p the opened sheet page
 * @param {{ width:number, expects:Array<object>, counts:Array<number> }} o expects = K.expectFormFromAttrs of each form in K.FORM_SLOTS order;
 *        counts = for each form the number of fields the RESOLVED markup gives it (K.formAttrs(...).fields)
 * @returns {Promise<{ problems:Array<[string,string]>, observations:Array<object>, formsChecked:number }>}
 */
async function checkForms(p, { width, expects, counts }) {
  const problems = [];
  const slots = K.FORM_SLOTS;
  const arg = slotArgs();
  const rest = await p.evaluate(K.measureForms, arg);
  const count = K.formCountProblems(rest.forms, counts, slots);
  if (count.length) return { problems: count, observations: [], formsChecked: 0 };

  // The painted fills and surfaces, with the placeholders, the admin bar and every fixed element out of the way.
  const paint = await paintForms(p, rest.forms);

  const observations = [];
  for (let i = 0; i < slots.length; i++) {
    const slot = slots[i];
    const exp = expects[i];
    const m = rest.forms[i];
    const where = slot.key;
    // A real hover on the submit button, then measure again.
    const wait = Math.min(2000, Math.max(transitionMs(m.submit.el), transitionMs(m.submit.wrap))) + 150;
    let hovAll = null; let hov = null;
    try {
      await p.locator(`[data-aied-fs="${slot.key}"]`).first().hover({ timeout: 8000 });
      await p.waitForTimeout(wait);
      hovAll = await p.evaluate(K.measureForms, arg);
      hov = hovAll.forms[i];
    } catch (e) {
      problems.push(['form-button', `${where} submit could not be hovered: ${(e && e.message ? e.message : String(e)).split('\n')[0]}`]);
    } finally {
      await p.mouse.move(Math.max(0, width - 2), 450); // parked at the right edge: (0,0) would open the admin bar's menu
      await p.waitForTimeout(wait);
    }
    const hovered = hov && hov.found ? hov : null;
    const miss = K.missingFormExpectations(exp);
    const cmp = K.compareForm(exp, m, 'rest');
    // Nothing of the kit may land on the form's container on hover either (the lift and the shadow belong to the submit button).
    const hoverContainer = hovered ? K.formContainerProblems(hovered.container, 'hover') : [];
    const btn = miss.length ? [] : K.formButtonProblems(exp.button, { el: m.submit.el, wrap: m.submit.wrap }, hovered ? { el: hovered.submit.el, wrap: hovered.submit.wrap } : null);
    const painted = K.formPaintedFillProblems(m, paint.fills[i], paint.surfaces[i]);
    const contrast = K.formContrastProblems(exp, m, paint.fills[i], paint.surfaces[i], hovered ? hovered.submit.el : null);
    const clip = [...K.formClipProblems(m, 'rest'), ...(hovered ? K.formClipProblems(hovered, 'hover') : [])];
    // The page-wide scrollWidth finding is the same for every form: report it once (with the first form), not once per form.
    const ov = [...K.formOverflowProblems(m, 'rest', rest), ...(hovered ? K.formOverflowProblems(hovered, 'hover', hovAll) : [])].filter(([, d]) => i === 0 || !/scrollWidth/.test(d));
    for (const [code, detail] of [...cmp, ...hoverContainer, ...btn, ...painted, ...contrast.problems, ...clip, ...ov]) problems.push([code, `${where} ${detail}`]);
    observations.push({
      width, key: slot.key, on: slot.on, fieldFills: paint.fills[i].map((c) => (c ? K.hex(c) : null)), surface: paint.surfaces[i].submit ? K.hex(paint.surfaces[i].submit) : null,
      contrast: contrast.info, radius: m.fields[0] ? m.fields[0].el.borderTopLeftRadius : null,
      shadow: K.parseShadows(m.submit.el.boxShadow)[0] ? m.submit.el.boxShadow : hovered && K.parseShadows(hovered.submit.el.boxShadow)[0] ? hovered.submit.el.boxShadow : null,
      captchaInk: m.captcha.question ? m.captcha.question.color : null,
    });
  }
  return { problems, observations, formsChecked: slots.length };
}

module.exports = { checkForms };
