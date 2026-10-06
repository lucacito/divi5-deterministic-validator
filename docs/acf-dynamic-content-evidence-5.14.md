# ACF dynamic content in Divi 5: evidence (backlog 2b), Divi 5.14.0

Run 2026-10-06 on the Docker dev site (Divi 5.14.0, free ACF 6.8.10 installed for the run and removed afterwards, our free plugin 4.7.0). Script: `scripts/spike-acf-dynamic-content.php` (self-cleaning, dev sites only; it needs the free ACF plugin active).

## Questions and answers

1. **How does Divi 5 bind a module to an ACF field?** Through its *Custom Field* dynamic-content source, not an ACF-specific one. Divi's own source (`DynamicContentOptionPostMetaKey.php`, `DynamicContentACFUtils.php`) lists ACF fields in that source's dropdown under an "Advanced Custom Fields" group and resolves their values, including ACF image return formats. A separate `acf_groups` source exists only for ACF Pro repeaters inside loops, and is out of scope here.
2. **What does the binding look like when stored?** One string, in the same `$variable(...)$` wrapper as global variables:
   `$variable({"type":"content","value":{"name":"post_meta_key","settings":{"before":"","after":"","select_meta_key":"custom_meta_<field_name>","meta_key":"","date_format":"default","custom_date_format":"","enable_html":"off"}}})$`
   (`before` and `after` wrap the value, for example `<h2>` and `</h2>`.) This is the form in a real export the owner made in the builder (Theme Builder template, Text and Blurb modules, builds 5.2.1 and 5.3.3; the field names there are the client's and are not copied into the repo). Divi 5.14's own render code also accepts a "simple" form, `"name":"custom_meta_<field_name>","settings":{}`, which it calls preferred; no real export holds it.
3. **Does our gate accept it?** Yes. The validator passes the owner's whole export, and the plugin gate (validator plus code policy) passed every probe below. The only refusals on that export are its custom CSS and code modules, by design.
4. **Does Divi render it?** Yes, in every slot tried (table). Checked on the front end of throwaway published pages, with the page's own module markup (the served page id matched the probe page each time, and no `$variable(` token leaked into the HTML).

## Probes (each a throwaway page; ACF values were set with `update_field`)

| Case | Slot and form | Rendered |
|---|---|---|
| A | Text `content.innerContent`, legacy form, ACF text field | `<p>AIED-ACF-TEXT-VALUE</p>` |
| B | same, simple form | same |
| C | Text, ACF textarea field | value present |
| D | Text with `before` `<h2>Hi ` and `after` `!</h2>` | `<h2>Hi AIED-ACF-TEXT-VALUE!</h2>` |
| E | Image `image.innerContent.desktop.value.src`, ACF image, return format **array** | `<img src=".../aied-acf-spike.png">` |
| F | same, return format **id** | same |
| G | same, return format **url** | same |
| H | Image, simple form, array | same |
| I | Button `button.innerContent.desktop.value.linkUrl`, ACF URL field | `href="https://example.com/aied-acf-link"` |
| J | Button text bound to a text field | value present |
| K | Text, ACF number field (42) | `<p>42</p>` |
| L | Blurb `title.innerContent.desktop.value.text` | value present |
| M | Text bound to a field that does **not exist** | **an empty module** (`<div class="et_pb_text_0 ..."></div>`): no error, no placeholder, nothing visible |
| N | control, literal text | literal |

## Findings that matter for the product

- **No validator change is needed.** Bindings are already valid. The style-reference advisory's `gcid-`/`gvid-` patterns do not match them, so no false flags.
- **A dead binding is silent**, exactly like an unknown variable (case M): the module renders empty. That is the same failure the existing advisories exist for, so a non-blocking "unknown custom field" advisory fits.
- **A field group assigned to a different post type** also renders empty (Divi reads the stored value, and ACF stores values only for posts the group applies to). `get_site_structure` lists groups, fields, types and choices but not each group's location rules; adding them would let the AI choose fields that apply to the page it is editing. Not probed here (needs one more case).
- **`get_site_structure`'s ACF listing, run for the first time against a real ACF install** (4 fields: text, image, select with choices, repeater): it returned the group key and title and each field's name, label and type, with the select's choices as value/label pairs. It worked as designed.
- **What is NOT proven:** which of the two forms the Divi 5.14 builder itself emits for a newly picked ACF field (the real export is from 5.2.1 to 5.3.3 builds); and that the builder emits the same string for the Image `src` and Button `linkUrl` slots (the render accepts it; no real export holds those two slots bound). Both are low risk, since both forms render, but guides should only teach what a real export shows: the legacy form in the Text and Blurb slots, and say the same string is accepted in the Image `src` and Button `linkUrl` slots.
- **Out of scope, not probed:** ACF Pro repeaters and loops, term and user meta, date fields (Divi has date-format settings for them), dynamic content in Theme Builder templates (a Pro-side area).

## Privacy

The owner's export holds real client data (email addresses, a phone number, client URLs, image data). None of it is in the repo: the fixture `fixtures/valid/page-18-acf-dynamic-content.json` uses placeholder field names (`team_member_name` and so on) and placeholder text only.

## Environment note

ACF was installed with `wp plugin install advanced-custom-fields --activate`, then deactivated and its folder removed (never `wp plugin delete`), its two options (`acf_first_activated_version`, `acf_site_health`) deleted, and a check found no leftover pages, attachments or field groups.
