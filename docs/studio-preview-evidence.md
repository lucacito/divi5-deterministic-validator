# Lab (Studio) live preview: spike evidence

Task 1 of the Studio screen plan. Question: can the screen's live preview be ONE private draft page that is updated again and again and shown in an iframe, or must it fall back to an approximation?

**Decision: `PREVIEW_MODE = 'draft'`**

All checks below PASS. Measured on the Docker dev site (Divi 5.15.1, WordPress 6.7, PHP 8.3) with a real Chromium (Playwright) and a real administrator session. Script: `scripts/spike-studio-preview.cjs` (dev site only; raw numbers are written to `dist/studio-preview/results.json`). It ran three times: a first full green run, a second that failed only on a script defect in the later probes (a PHP string in the et-cache check; the browser checks had passed), and a third full green run; the numbers quoted are from the last. Every draft it creates is deleted: the count of pages titled `Lab preview%` or `aied studio%` was 0 before and 0 after.

## What was run

Three briefs, three design languages (editorial, brutalist-flat, glass-dark), each three sections: `hero-cta`, `stats-counter-band`, `cta-band`. Each compiled with `StudioCompiler::compile($brief, null, home_url())` (valid on the first try, 11 sample-copy slots left unfilled by design, 0 refused). Version 1 was inserted with `DraftPage::insert('Lab preview (temporary) 1', $markup, '')`; versions 2 and 3 were saved into the SAME post with `wp_update_post(wp_slash(['ID'=>$id,'post_content'=>$markup]))`. For each version the preview address was opened at 1280 and at 400 px. As a control, the same markup was also saved as a brand-new draft (`aied studio fresh N`, never rendered before) and measured the same way.

## Results

| # | Check | Result | Evidence |
|---|---|---|---|
| 1 | Preview address works for a draft | PASS | `get_preview_post_link($id)` = `http://localhost:8181/?page_id=<id>&preview=true`; HTTP 200 for all 3 versions at both widths, as the logged-in administrator |
| 2 | Real theme header and footer present | PASS | `header.et-l` and `footer.et-l` (Divi's Theme Builder wrappers) found in every version at 1280 and 400; the page has 4 `.et_pb_section` in the main area (3 from the brief plus one more) |
| 3 | Styling matches the brief's language after each update (CSS regenerated) | PASS | For every version and width the updated draft's computed styles (headline font family, weight, size, spacing, case, colour; button background, colour, radius, border, padding; the first three sections' background and padding) are identical to the never-rendered control page. The three versions differ from each other (3 distinct style signatures), so the first version's CSS is not served again |
| 4 | Iframe in an admin page loads | PASS | An `<iframe>` pointing at the preview address, injected into `wp-admin/index.php`, loads at 900 and 400 px wide: header, footer and sections present, same-origin `contentDocument` readable, no horizontal overflow at 400 px (phone layout). The response has no `X-Frame-Options` and no `Content-Security-Policy` header (WordPress sends the frame-blocking header only for admin and login screens) |
| 5 | Literal text for `&`, `<`, `$`, `"` | PASS | See the table below |
| 6 | Not in the front-end, sitemap, search | PASS | Anonymous requests (no cookies) to `/`, `/?s=preview+temporary`, `/?s=Divi+5+Lab`, `/feed/`, `/wp-sitemap.xml`, `/wp-sitemap-posts-page-1.xml`, `/?post_type=page`, `/wp-json/wp/v2/pages?per_page=5`, `.../pages?search=preview`, `.../search?search=preview` all answer 200 without the title, the id or the address; `/?page_id=<id>` and `/?page_id=<id>&preview=true` answer 404; `/wp-json/wp/v2/pages/<id>` answers 401 |
| 7 | Shows in `list_divi_pages` | PASS | `{"id":<id>,"title":"Lab preview (temporary) 1","status":"draft","post_type":"page","link":"http://localhost:8181/?page_id=<id>"}`: the title as stored, with `status: draft`; nothing marks it as the Studio's draft except the title text |
| 8 | No uncaught page errors | PASS | none in any render |

### Escaping (closes plan 1's open risk)

The text sent was `V1 Fish & Chips <b>bold</b> costs $5 "now"` (and similar). The compiler HTML-escapes plain fields and writes `$` and `{` as entities. What the real Divi 5.15.1 puts on screen (`textContent`, exact string equality with the typed text):

| Field | Shown literally? |
|---|---|
| Heading (`heading.1`) | PASS, `V1 Fish & Chips <b>bold</b> costs $5 "now"` |
| Second heading (`cta-band` `heading.0`, `Call 1: ask & get $1 "free"`) | PASS |
| Button label (`button.0.text`, `Buy & save <i>$5</i> "now" 1`) | PASS |
| Counter title (`number-counter.0.title`, the full text above plus " counter") | PASS |
| Text module body (`text.0.body.0`, `Body & <u>text</u> $9 "q" 1`) | PASS (rendered as text, no `<u>` element) |
| Counter number | The number field cannot hold these characters (StudioBrief allows plain numbers only, FIGURE_NOT_NUMBER). The legal value `1,250` displays as `1,250` after Divi's count-up animation (markup `<span class="percent-value">1,250</span><span class="percent-sign"></span>`). The animation takes about 2 seconds; a screenshot taken sooner shows a lower number |

No double-escaping (`&amp;amp;`) and no raw `&#36;` showed anywhere. The open item in `docs/studio-compiler.md` ("whether Divi shows `&amp;` or `&#36;` literally...") is answered: it does not; Divi decodes the entities and shows the typed characters. Limit: only heading, button label, counter title and text body were rendered here; other plain fields (blurb title, testimonial author, accordion titles, form labels) use the same fill code, but were not each rendered.

## Facts for Task 5

- **Draft:** one per user, title exactly `Lab preview (temporary) <userId>`. Create with `DraftPage::insert($title, $markup, '')` (draft, builder flags set). Update with `wp_update_post(wp_slash(['ID'=>$id,'post_content'=>$markup]), true)`. Both returned the same id every time.
- **Preview URL:** `get_preview_post_link($id)` returns `home_url('/?page_id=<id>&preview=true')` (no nonce for a draft). It only works with the author's login cookie, which an iframe inside wp-admin sends. Anonymous requests get 404.
- **CSS refresh:** Divi writes the page CSS INLINE in `<style>` tags (about 15 on this page, the first section's rule `et_pb_section_0` among them) for a draft preview; no `et-cache/<id>` file or directory is created (`glob(WP_CONTENT_DIR/et-cache/<id>*)` was empty), so there is nothing to go stale and **no cache-clear call is needed**. Version 3 showed version 3's styles directly after `wp_update_post`, with no extra step. The response is `Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private`, so the browser does not keep an old copy; to make the iframe reload after an update, set `iframe.src` to the address again (the spike loaded a fresh iframe each time; a cache-busting query argument was not needed and was not tested).
- **Iframe headers:** none block framing. Same-origin, so the screen may read `contentDocument` (for the height). The admin page's own `X-Frame-Options` does not matter (it is the parent). If a site adds a security plugin that sends `X-Frame-Options` on the front end, the iframe would be blank: the screen should show a plain "preview could not load, open it in a new tab" link next to the iframe.
- **Timing:** Divi's counter animation and fonts settle in about 2.5 seconds. Entrance animations in recipes may replay. Nothing in the spike needed a longer wait.
- **Robots:** the preview page carries `<meta name='robots' content='max-image-preview:large'>` but no `noindex`; it does not need one because anonymous visitors get 404.
- **History pollution (must handle):** `HistoryCapture` listens to `pre_post_update`, so each `wp_update_post` on the preview draft wrote one undo snapshot (2 rows after the 2 updates). They are removed when the draft is permanently deleted (`delete_post` hook, `HistoryStore::deleteForPage`; 0 orphan rows afterwards), but until then they fill the page's 10-snapshot budget and the Dashboard's recent edits. Task 5 should save the preview content inside `HistoryCapture::suspend(fn () => wp_update_post(...))` (the same wrapper `HistoryService` uses), so the preview leaves no history rows. This was not run in the spike (the spike measured the unwrapped behaviour).
- **`list_divi_pages`:** the draft is listed (status `draft`). Owners who connect an AI will see it in that list under its title; the words "(temporary)" are the only hint. Task 5 may keep the title (the rule above) and mention it in the screen's help text.
- **User rights:** the spike ran as user 1 (administrator, has `unfiltered_html`). For a user without it, `wp_update_post` re-runs kses over the content; this was not tested. `LayoutGate` already forbids custom code, but a lower role could have attributes altered. The Studio screen should require the same capability as the other page tools (`edit_pages` plus `edit_post` on the draft) and Task 5 should test it with an editor.

## Cleanup rule

The preview draft is temporary: delete it (`wp_delete_post($id, true)`, permanent, which also removes its history) when the owner clicks Create (after the real draft page exists), and delete any page whose title starts with `Lab preview (temporary)` and whose last modification is more than 24 hours old (a scheduled or on-load sweep for a user who closed the browser). Never delete by search; match the exact title prefix and `post_type=page`, `post_status=draft`. The spike's own control pages use the prefix `aied studio fresh ` and are deleted by the script.

## What was NOT tested

- The Studio screen itself (it does not exist yet); the preview of a very large brief (the largest here was 13 KB of markup, 3 sections).
- A user role below administrator; a site with a security plugin that adds frame-blocking headers; a multisite install.
- Divi's behaviour on a PUBLISHED page (not needed: the preview is always a draft).
- Scroll-triggered animations beyond the 2.5 s wait; reduced-motion.
- Whether the browser's back button or two parallel browser tabs updating the same user's draft behave well (one draft per user means the last write wins).
- Dev-site note: `GET /wp-json/wp/v2/pages?per_page=100` answers HTTP 500 for an anonymous visitor on this dev site (the site holds many large Divi pages and the response renders each). It happens without the spike's draft, so it is unrelated; the spike uses `per_page=5`.

## Later: what the screen's live gate (`make verify-studio`) changed

- **User rights (was not tested):** for a user without `unfiltered_html` the preview draft and a created page WERE broken by kses (block comments with raw `<` in their JSON became visible text). Fixed in `StudioCompiler` (block attributes written as WordPress serializes them); now the same blocks and values are stored, and the page renders the typed text literally. Details in `docs/studio-compiler.md` ("What the gate found").
- **History pollution:** handled as planned. With the real `HistoryCapture` hook registered, two preview updates write no history row and no revision (a control save of the same draft outside the preview path IS captured).
- **Cleanup rule:** a draft that was inserted and never updated has `post_modified_gmt` 0000-00-00 00:00:00, which made every new preview draft "stale". The sweep now reads the local `post_modified` for such a row.
- **Counter number:** Divi starts the count-up when the counter scrolls into view; a counter below the fold shows an empty number until then.
