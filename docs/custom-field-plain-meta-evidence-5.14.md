# Plain (non-ACF) post meta binding: render evidence, Divi 5.14.0

Date: 2026-10-07. Script: `scripts/verify-custom-fields.php` (`make verify-custom-fields`), real Docker site.

- Setup: a published page with a plain post meta key `aied_plain_meta` = `Plain value 7f3a` (no ACF installed) and a Text module whose content is the legacy binding string with `select_meta_key: custom_meta_aied_plain_meta`.
- Result: PASS the front end showed the value; PASS no `$variable(` token leaked.
- Verdict: teach binding to a plain meta key in the style guide.
- Also verified live in the same run: get_custom_fields over REST and MCP (plain key, JSON array value, protected/secret keys withheld, keys filter, bad input), non-public type looks missing, contributor refused, discovery lists the key and no value, reads leave post meta byte-identical. Project read: PASS (the dev site has the `project` type).
