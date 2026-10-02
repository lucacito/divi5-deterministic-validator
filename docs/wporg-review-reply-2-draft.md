# Reply to the WordPress.org review — DRAFT (the owner sends it; nothing here was sent)

Review ID: `R jhmg-ai-editor-for-divi-5/lucaslopvet/1Oct26/T2 2Oct26/4.3`

Before sending: upload the new `jhmg-ai-editor-for-divi-5.zip` (version 4.4.0) at "Add your plugin", and test it on Playground / a clean install with WP_DEBUG on.

---

Hello,

Thank you for the review. You are right: the layout tools accepted arbitrary Divi block attributes, including the `css.freeForm` / `css.mainElement` fields, and our own style guide documented them. That is fixed in the version I just uploaded (4.4.0).

What changed:

1. One extra check now runs after our structure validator on every path that saves or previews a layout (`update_page_layout`, `edit_page_content`, `create_page`, `validate_layout`, `dry_run` and the matching REST routes). It refuses: any custom-CSS field in a module's `css` attribute, the Divi Code modules (`divi/code`, `divi/fullwidth-code`), `<script>`, `<style>`, `<iframe>`, `<object>` and `<embed>` markup, `javascript:` URLs, event-handler attributes (`onclick`, `onerror`, …) and PHP tags. Code hidden by JSON escaping is found too. The content is also checked against a second, independent reading of the block markup, so crafted comments cannot make WordPress and our checker disagree about what a block contains. Very large or very deeply nested layouts (over 1,000,000 bytes, 10,000 comment markers or 100 nested blocks) are refused with a clear message instead of being checked partially. A refusal names the exact path, so the AI assistant corrects itself.
2. The AI guides no longer teach custom CSS, and the three bundled section recipes that carried `freeForm` CSS were rewritten without it.
3. The one exception is code already stored on a page, for example CSS the site owner added in the Visual Builder: an edit to that page keeps it untouched, and the AI still cannot add or change code. New pages and validation allow none.
4. The plugin has no tool that saves CSS, JavaScript or PHP, changes site settings or runs code (removed in 4.0.0). A test in our suite fails if a guide, recipe or write path ever allows custom code again.

We also went through the rest of the plugin for the same kind of issue (every value that can be saved: layout attributes, find-and-replace edits, new pages, history restore, which only brings back the owner's earlier content) and found no other path that saves custom CSS, JavaScript or PHP. Page and post titles are now stripped of markup too.

Tested: Plugin Check is clean on the code and on the zip, and with debug logging enabled the plugin logged no notice or warning during our tests.

Thank you,
Lucas
