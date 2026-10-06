# Global variables: live evidence on Divi 5.14

Source: `scripts/spike-global-variables.php`, run on the Docker dev site (Divi 5.14 active, user 1). The stored `global_variables` option was snapshotted first and restored byte for byte (`restored_byte_identical: true`).

```json
{
    "divi_user_can_edit_theme_options": true,
    "variables_manager_allowed": true,
    "raw_is_array": true,
    "raw_groups": [],
    "raw_sample_per_group": [],
    "read_groups": {
        "numbers": 0,
        "strings": 0,
        "images": 0,
        "links": 0,
        "colors": 0,
        "fonts": 2,
        "gradients": 0
    },
    "read_fonts_ids": [
        "--et_global_heading_font",
        "--et_global_body_font"
    ],
    "customizer_fonts_ids": [
        "--et_global_heading_font",
        "--et_global_body_font"
    ],
    "read_sample_has_allowed": null,
    "noop_roundtrip_identical": true,
    "stored_number": {
        "label": "AIED spike number",
        "value": "clamp(1rem, 2vw + 1rem, 3rem)",
        "order": "99",
        "status": "active"
    },
    "stored_string": {
        "label": "AIED spike string",
        "value": "AIEDSPIKEMARKER Hello world",
        "order": "99",
        "status": "active"
    },
    "stored_order_type": "string",
    "untouched_unchanged": true,
    "emit_page_bytes": 611955,
    "emit_number_id_contexts": [],
    "emit_string_id_contexts": [],
    "emit_string_marker_contexts": [],
    "emit_any_gvid_definitions": 0,
    "archived_number_emitted": false,
    "archived_stored_status": "archived",
    "restored_byte_identical": true
}
```

## Stored shape

The dev site had no stored variables at all (`raw_groups` and `raw_sample_per_group` are empty), so no pre-existing group's field names or types were observed. For the two variables the spike wrote, Divi stored exactly `label`, `value`, `order`, `status`, with `label`, `value` and `status` as strings and `order` stored as the string `"99"` even though an integer 99 was written (`stored_order_type: "string"`). The six-group write left the other (empty) groups absent from the raw option; `read_sample_has_allowed` is null because no number variable existed before the write, so `allowedActions` was not observed.

## Customizer fonts

`read_fonts_ids` and `customizer_fonts_ids` are both `["--et_global_heading_font", "--et_global_body_font"]`; neither starts with `gvid-` (they start with `--et_global_`), and Divi's read returns them in the `fonts` group (2) although nothing is stored for fonts.

## No-op round trip

`noop_roundtrip_identical: true`. Writing the stored six-group content back through `GlobalData::set_global_variables()` left the option byte-identical (on this empty store, so it exercises the empty case only).

## Permission

`variables_manager_allowed: true` for user 1 (and `edit_theme_options` true). That Divi silently does nothing for a user without this permission comes from reading Divi's source, not from a live run (not exercised live).

## Emission

With a number variable and a string variable stored and active, and nothing citing either, the home page plus its same-site stylesheets (611955 bytes fetched) contained neither id (`emit_number_id_contexts: []`, `emit_string_id_contexts: []`), nor the string marker (`emit_string_marker_contexts: []`), and no `--gvid-...:` definition (`emit_any_gvid_definitions: 0`). So neither the number value nor the string value is emitted into the page or its CSS unless something cites it. Emission when a layout does cite a variable was not tested here.

## Archived

`archived_number_emitted: false` (it was not emitted when active either, since nothing cited it), and `archived_stored_status: "archived"`, so Divi stores the `archived` status as written.

## Decisions for the guards

string guard stays as the spec (the string marker and string id did not appear anywhere in the page HTML or CSS, so no unescaped emission path was observed).
