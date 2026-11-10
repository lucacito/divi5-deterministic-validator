/* JHMG AI Editor for Divi 5: Divi 5 Lab, the pure part.
 *
 * Functions with no DOM and no requests: building the brief from the form state, merging words an assistant wrote,
 * moving sections, the item-count guard and the layout swap (the same rule as StudioMerge::swapRecipe on the server:
 * a value moves only to a slot with the same id AND role AND kind). studio.js uses them in the browser;
 * scripts/test-studio-ui.cjs tests them with node. Nothing here builds HTML.
 */
(function (root, factory) {
    'use strict';
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.aiedLabCore = factory();
    }
}(this, function () {
    'use strict';

    var own = function (o, k) { return Object.prototype.hasOwnProperty.call(o, k); };

    /** printf-style: %s and %d in order, or %1$s / %2$d by position. */
    function format(text) {
        var args = Array.prototype.slice.call(arguments, 1);
        var next = 0;
        return String(text).replace(/%(?:(\d+)\$)?([sd])/g, function (all, pos) {
            var i = pos ? parseInt(pos, 10) - 1 : next++;
            return i < args.length ? String(args[i]) : all;
        });
    }

    /** "hero-split-right" => "Hero split right". */
    function humanize(id) {
        var s = String(id || '').replace(/[-_]+/g, ' ').trim();
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : s;
    }

    function slotsOf(catalog, recipe) {
        return catalog && own(catalog, recipe) ? catalog[recipe].slots : [];
    }

    function clone(v) { return JSON.parse(JSON.stringify(v)); }

    function newSection(recipe) {
        return { recipe: recipe, on: true, slots: {}, pictures: {}, auto: {} };
    }

    function sectionsFromStack(stack) {
        return (stack || []).map(newSection);
    }

    /** A copy of list with the item at from moved to to (clamped). */
    function move(list, from, to) {
        var out = list.slice();
        if (from < 0 || from >= out.length) { return out; }
        to = Math.max(0, Math.min(out.length - 1, to));
        var item = out.splice(from, 1)[0];
        out.splice(to, 0, item);
        return out;
    }

    function filled(v) { return typeof v === 'string' ? v.trim() !== '' : !!v; }

    /** How many items of a section the person filled: its item titles with words in them. */
    function filledItems(section, catalog) {
        var n = 0;
        slotsOf(catalog, section.recipe).forEach(function (slot) {
            if (slot.role === 'item_title' && filled(section.slots[slot.id])) { n++; }
        });
        return n;
    }

    /**
     * The layouts a section can switch to: every recipe with the same role, in catalog order. A layout that holds fewer
     * items than the person filled is not allowed (spec rule 3: more items than a layout holds is prevented).
     */
    function variants(section, catalog) {
        var current = catalog[section.recipe];
        var have = current ? filledItems(section, catalog) : 0;
        var out = [];
        Object.keys(catalog).forEach(function (name) {
            var r = catalog[name];
            if (current && r.role !== current.role) { return; }
            out.push({
                recipe: name,
                role: r.role,
                items: r.items,
                filled: have,
                current: name === section.recipe,
                allowed: name === section.recipe || r.items >= have
            });
        });
        return out;
    }

    function roleKinds(catalog, recipe) {
        var map = {};
        slotsOf(catalog, recipe).forEach(function (s) { map[s.id] = s.role + '/' + s.kind; });
        return map;
    }

    /** Change a section's layout; keeps a value only where id, role and kind all match (StudioMerge::swapRecipe). */
    function swap(section, newRecipe, catalog) {
        if (!own(catalog, newRecipe)) { return { section: section, kept: [], dropped: [] }; }
        var before = roleKinds(catalog, section.recipe);
        var after = roleKinds(catalog, newRecipe);
        var out = { recipe: newRecipe, on: section.on, slots: {}, pictures: {}, auto: {} };
        var kept = [];
        var dropped = [];
        ['slots', 'pictures'].forEach(function (key) {
            Object.keys(section[key] || {}).forEach(function (id) {
                if (!filled(section[key][id])) { return; }
                if (own(after, id) && own(before, id) && after[id] === before[id]) {
                    out[key][id] = section[key][id];
                    if (section.auto && section.auto[id]) { out.auto[id] = true; }
                    kept.push(id);
                } else {
                    dropped.push(id);
                }
            });
        });
        return { section: out, kept: kept, dropped: dropped };
    }

    /**
     * The brief the server expects, from the form state. Only included sections, only slots the recipe has, only words
     * that are not empty; pictures under "pictures". map[k] is the state index of brief section k.
     */
    function buildBrief(state, catalog, untitled) {
        var brief = {
            version: 1,
            title: (state.name || '').trim() || untitled,
            goal: state.goal,
            language: state.language,
            sections: []
        };
        if (state.kit) { brief.kit = state.kit; }
        var map = [];
        state.sections.forEach(function (section, i) {
            if (!section.on) { return; }
            var out = { recipe: section.recipe };
            var slots = {};
            var pictures = {};
            slotsOf(catalog, section.recipe).forEach(function (slot) {
                if (slot.kind === 'picture') {
                    var p = section.pictures[slot.id];
                    if (p && typeof p === 'object' && (filled(p.pack) || filled(p.url))) {
                        pictures[slot.id] = filled(p.pack) ? { pack: p.pack } : { url: p.url.trim() };
                    }
                } else if (filled(section.slots[slot.id])) {
                    slots[slot.id] = section.slots[slot.id].trim();
                }
            });
            if (Object.keys(slots).length) { out.slots = slots; }
            if (Object.keys(pictures).length) { out.pictures = pictures; }
            brief.sections.push(out);
            map.push(i);
        });
        return { brief: brief, map: map };
    }

    function firstSlot(catalog, recipe, roles) {
        var slots = slotsOf(catalog, recipe);
        for (var r = 0; r < roles.length; r++) {
            for (var i = 0; i < slots.length; i++) {
                if (slots[i].role === roles[r] && slots[i].kind === 'text') { return slots[i].id; }
            }
        }
        return null;
    }

    /**
     * Step 1 offered to the opening section (controller ruling 16): the business name as its headline, the one line as
     * its supporting line, the one action as its button label. A field is written only while it is empty or still holds
     * a value put there this way; once the person edits it, it is theirs.
     */
    function applyStepOne(state, catalog) {
        var next = clone(state);
        var hero = null;
        next.sections.forEach(function (s) {
            if (!hero && s.on && catalog[s.recipe] && catalog[s.recipe].role === 'hero') { hero = s; }
        });
        if (!hero) { return next; }
        hero.auto = hero.auto || {};
        [[['headline'], next.name], [['subhead', 'body'], next.line], [['button_label'], next.action]].forEach(function (pair) {
            var id = firstSlot(catalog, hero.recipe, pair[0]);
            if (!id) { return; }
            var value = (pair[1] || '').trim();
            var current = hero.slots[id];
            if (!filled(current) || hero.auto[id]) {
                if (value) {
                    hero.slots[id] = value;
                    hero.auto[id] = true;
                } else {
                    delete hero.slots[id];
                    delete hero.auto[id];
                }
            }
        });
        return next;
    }

    function same(a, b) { return JSON.stringify(a === undefined ? null : a) === JSON.stringify(b === undefined ? null : b); }

    /**
     * Words an assistant wrote into the stored brief, merged into the form. stored is the brief read back, synced the brief
     * last sent from this screen, map the state index of each brief section. A value is taken when the form's field is
     * empty or still what was last sent; a field the person changed here since is kept and counted.
     */
    function mergeAssistant(state, stored, synced, map, catalog) {
        var next = clone(state);
        var applied = 0;
        var kept = 0;
        ((stored && stored.sections) || []).forEach(function (sec, k) {
            var i = map[k];
            if (i === undefined || !next.sections[i] || next.sections[i].recipe !== sec.recipe) { return; }
            var target = next.sections[i];
            var base = (synced && synced.sections && synced.sections[k]) || {};
            var kinds = roleKinds(catalog, sec.recipe);
            ['slots', 'pictures'].forEach(function (key) {
                Object.keys(sec[key] || {}).forEach(function (id) {
                    if (!own(kinds, id)) { return; }
                    var incoming = sec[key][id];
                    var current = target[key][id];
                    if (same(incoming, current)) { return; }
                    if (!filled(current) || same(current, (base[key] || {})[id])) {
                        target[key][id] = incoming;
                        if (target.auto) { delete target.auto[id]; }
                        applied++;
                    } else {
                        kept++;
                    }
                });
            });
        });
        return { state: next, applied: applied, kept: kept };
    }

    /** Problems by state section ({path, code, message} lists); a path outside sections.N goes to page. */
    function problemsBySection(problems, map) {
        var out = { page: [], sections: {} };
        (problems || []).forEach(function (p) {
            var m = /^sections\.(\d+)(?:\.(.*))?$/.exec(String((p && p.path) || ''));
            var i = m ? map[parseInt(m[1], 10)] : undefined;
            if (i === undefined) {
                out.page.push(p);
                return;
            }
            var slot = m[2] ? m[2].replace(/^(slots|pictures)\./, '') : '';
            (out.sections[i] = out.sections[i] || []).push({ path: p.path, code: p.code, message: p.message, slot: slot });
        });
        return out;
    }

    /** report.unfilled ({section, slot, role}) by state section. */
    function unfilledBySection(unfilled, map) {
        var out = {};
        (unfilled || []).forEach(function (u) {
            var i = map[u.section];
            if (i === undefined) { return; }
            (out[i] = out[i] || []).push({ slot: u.slot, role: u.role });
        });
        return out;
    }

    function origin(url) {
        var m = /^(https?:\/\/[^/?#]+)/i.exec(String(url || ''));
        return m ? m[1].toLowerCase() : null;
    }

    /**
     * A Media Library address as the brief takes it: an address on this site becomes its path (/wp-content/...), so an
     * http site works too; another https address stays; anything else is null.
     */
    function toSitePath(url, home) {
        url = String(url || '').trim();
        var o = origin(url);
        var h = origin(home);
        if (o && h && o === h) {
            var rest = url.slice(o.length);
            return rest.charAt(0) === '/' ? rest : '/' + rest;
        }
        return /^https:\/\//i.test(url) ? url : null;
    }

    /** An address the screen may link to: only one on the same site as the screen. */
    function sameSiteUrl(url, here) {
        return origin(url) !== null && origin(url) === origin(here) ? String(url) : null;
    }

    /** The form state from a stored brief: which sections lost their layout, which saved words lost their place. */
    function fromStored(brief, catalog) {
        var state = {
            name: typeof brief.title === 'string' ? brief.title : '',
            goal: brief.goal,
            language: brief.language,
            kit: typeof brief.kit === 'string' ? brief.kit : '',
            sections: []
        };
        var gone = [];
        var stale = {};
        (brief.sections || []).forEach(function (sec, i) {
            var s = newSection(String(sec.recipe || ''));
            s.slots = clone(sec.slots || {});
            s.pictures = clone(sec.pictures || {});
            if (!own(catalog, s.recipe)) {
                gone.push(i);
            } else {
                var kinds = roleKinds(catalog, s.recipe);
                var lost = Object.keys(s.slots).concat(Object.keys(s.pictures)).filter(function (id) { return !own(kinds, id); });
                if (lost.length) { stale[i] = lost; }
            }
            state.sections.push(s);
        });
        return { state: state, gone: gone, stale: stale };
    }

    return {
        format: format,
        humanize: humanize,
        newSection: newSection,
        sectionsFromStack: sectionsFromStack,
        move: move,
        filledItems: filledItems,
        variants: variants,
        swap: swap,
        buildBrief: buildBrief,
        applyStepOne: applyStepOne,
        mergeAssistant: mergeAssistant,
        problemsBySection: problemsBySection,
        unfilledBySection: unfilledBySection,
        toSitePath: toSitePath,
        sameSiteUrl: sameSiteUrl,
        fromStored: fromStored
    };
}));
