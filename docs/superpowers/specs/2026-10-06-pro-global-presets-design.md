# Pro: create and update the site's module presets (`manage_global_presets`, in the unreleased Pro add-on 0.8.0)

Status: implemented on branch feat/pro-global-presets (in the pending Pro 0.8.0, not released). Decisions taken by the owner in chat 2026-10-06 (free attribute tree guarded by the custom-code policy; no delete in v1; fold into the one pending Pro release instead of a new version). What was built, and where it differs from the design below, is under "Implementation notes" at the end. Date: 2026-10-06.
Source: `docs/feature-requests-2026-10-04.md` item 1 ("create and edit global colors, variables and presets"; colors and variables are built). Evidence: `docs/global-presets-evidence-5.14.md` (live probes on Divi 5.14; read it first).

## Goal

Let an AI assistant set the site's design system at the level of whole modules: create a module preset ("Heading 1: 57px, bold, 39px bottom margin") and later change it, so the pages it builds cite `modulePreset: ["<id>"]` and the whole site follows. Every preset change restyles every module that uses the preset (and a module's default preset restyles every module of that type without an explicit one), so it is previewed, validated, bound to a signed token, locked and undoable like the other global tools.

Pro add-on only. The free plugin stays read-only (`list_global_styles` lists presets; the guides already teach `modulePreset`; the unknown-id advisory exists). The validator is not touched.

## Evidence this rests on (Divi 5.14, details in the evidence doc)

- Stored shape: `{module: {<divi/module>: {default, items: {<uuid>: {id, moduleName, name, attrs, styleAttrs, [renderAttrs], version, type: "module", created, updated}}}}, group: ...}`.
- `GlobalPreset::process_presets_for_import( array $presets, true )` is Divi's own ADDITIVE write: it merges new presets in, never erases an existing one, did not remap a fresh uuid, set `created`/`updated`, left the module's `default` alone, and cleared Divi's caches. An import with an existing id and different content creates another preset instead of updating.
- For a module with no presets the import also makes Divi create a DEFAULT preset for it (total +2).
- `GlobalPreset::save_data( $complete )` replaces the whole stored data (the only way to update an item or remove one); it does not touch `updated`. Saving the current data minus an added item returned the option byte for byte.
- A page citing the id gets the class `preset--module--divi-heading--<id>` and Divi emits the preset's rules (font size and margin verified). An unknown id leaves the class with no rules.
- Divi's PHP preset functions check no capability (only its REST layer does), so the tool enforces it. Divi stores custom CSS placed in a preset's attrs and presets bypass `LayoutGate`, so the tool must run the custom-code policy itself.
- Legacy data exists in the wild (a module entry keyed `"0"`, a preset without `moduleName`): the tool must read and write around it without altering it.

## Scope

In: one new Pro tool `manage_global_presets` (MCP + REST only, the 17th Pro tool; the ChatGPT spec stays 16 free + 14 Pro = 30 operations); its REST route; an accessor class `GlobalPresets` (the only code that talks to Divi's preset functions); a planner; a site-wide lock; a batch item kind `global_preset`; tests with a Divi preset shim; a live gate `make verify-pro-global-presets`. Ships inside the pending Pro 0.8.0 (no new version number): changelog and README entries are added to the 0.8.0 section.

Out: deleting presets; GROUP (option-group) presets; renaming a module or moving a preset to another module; editing `renderAttrs`; the legacy D4 option; any change to a page layout; the ChatGPT Action; the free plugin and the validator.

## Design

### The tool

`manage_global_presets`, Pro capability `edit_theme_options` (checked by the registry AND again inside the accessor, because Divi's own functions do not), licence-gated like every Pro tool.

| Name | Type | Meaning |
|---|---|---|
| `presets` | list, 1 to 20 | The changes (entry below). |
| `dry_run` | boolean | Preview only; nothing is written. |
| `preview_token` | string | Required for a real run: the token from the matching dry run. |
| `allow_default_presets` | boolean | Default false. Required (and bound into the token) to UPDATE a module's default preset. |

Entry: `{ id?: string, module?: string, name?: string, attrs?: object }`.
- **No `id` = create.** `module` (a `divi/…` block type the bundled validator knows), `name` (1 to 60 characters) and `attrs` (a non-empty object) are all required. The new preset is never made the module's default.
- **`id` = update** of an existing, managed preset (a module entry whose key is a normal `divi/…` name and an item that has a `moduleName` equal to that key). At least one of `name`, `attrs`. `module` on an update is refused. `attrs` REPLACES the preset's style tree (`attrs` and `styleAttrs` both); `renderAttrs`, `version`, `type` and `created` are kept; `updated` is set to now. The dry run shows the stored `attrs` as `before`, so the assistant sees what it replaces.
- Atomic: an unknown or unmanaged id, a duplicate id in one call, two creates with the same module and name (case-insensitive), a default preset without `allow_default_presets`, or any bad entry refuses the WHOLE call with a reason per entry. No delete.

### Validation (before anything is written)

- `module`: `^divi/[a-z0-9-]+$` and a block type `SchemaRules` knows. `name`: as colors/variables (control characters and angle brackets refused, `sanitize_text_field`). `id`: `^[A-Za-z0-9-]{8,64}$` (Divi's own ids are uuids).
- `attrs`: a JSON object tree of scalars, lists and objects only; at most 20 KB when encoded and depth 12; no numeric-key explosion. **Custom code policy:** the attrs are run through the same `CodePolicy::violations()` the layout gate uses, as the attributes of one synthetic block of that module (`<!-- wp:<module> {attrs} /-->`), so custom CSS (`freeForm`, `mainElement`, any `css` family), scripts, embeds and unsafe style values are refused. The validator's per-module required-attribute rules are NOT applied (a preset holds partial attrs). Divi does not filter unknown attributes, so an attribute Divi does not understand is stored and silently does nothing; this is the accepted cost of a free tree.

### Writing safely (`GlobalPresets`)

1. **Read** only through `GlobalPreset::get_data()`; if the class or `get_data`/`save_data`/`process_presets_for_import` is missing or throws, refuse and write nothing. The result is used as returned (Divi's own normalised form).
2. **Dry run** returns `{ dry_run: true, changes: [ { index, id (null for a create), module, action: create|update, before, after, is_default, default_preset_created } ], preview_token, expires_in, message }` and writes nothing. `default_preset_created` is true when a create is for a module with no presets (Divi will add a default preset too, and the message says so). `is_default` marks an update of a default preset.
3. **Real run** needs the single-use `PreviewToken` (30 minutes, bound to the user and the arguments including `allow_default_presets`). An entry whose stored name or attrs changed since the preview is skipped and reported.
4. **Creates** go through `process_presets_for_import( [ 'module' => [ <module> => [ 'default' => <the module's current default or ''>, 'items' => [ <new uuid> => <item> ] ] ] ], true )` (additive). The item copies the keys of a real stored preset with `version` = the running Divi version. The id Divi stores is taken from `preset_id_mappings` when it remapped.
5. **Updates** build the COMPLETE current data with the replaced item(s) and hand it to `GlobalPreset::save_data()` once.
6. **Read back** with `get_data()` and report what is stored now: every preset NOT named in the call must be unchanged (a difference is reported as `unexpected_changes`), and each named preset's stored name and attrs are compared with what was requested (`differs_from_request`). A write Divi did not store is reported, never claimed.
7. **Pre-flight:** the data about to be saved must be an array with a `module` map whose entries are arrays with an `items` array; anything else refuses the call before a write.

### Concurrency

A site-wide `GlobalPresetsLock` (the build lock's atomic option row, prefix `aied_pro_buildlock_gpresets_`, so uninstall removes it; 30 s expiry) is held from BEFORE the first read of a real run until the read-back, and by every real `undo_batch` that still has an applied preset item: `GlobalPresetKind` implements `SiteLockingKind` (merged 2026-10-06). A dry run takes no lock. A save made in the Divi builder is not covered.

### Batch and undo

One batch per call, one item per changed preset, kind `global_preset`: `ref` = the preset id, `before` = the previous item (null for a create) plus its module and, for a create that made Divi add a default preset, that default preset's id and the fact that the module entry did not exist; `after_hash` = hash of the stored `{moduleName, name, attrs}`.
- Undo of an **update** puts the previous `name` and `attrs` back (and `updated` = now) through `save_data()` of the current data, only while the preset still looks the way the batch left it (else `changed_since`).
- Undo of a **create** removes the created preset; if the batch also caused Divi to add a default preset or the module entry, those go too, but only if still exactly as Divi created them (else `changed_since`). Even with `force` a created preset that changed since is never removed. A created preset that pages now cite loses its styling when removed (the preview says so).
- `allowed()` re-checks `edit_theme_options` at undo time.

### Wiring and release

Registered in `Tools::all()` (17th Pro tool); `ProOpenApi::MCP_REST_ONLY` gets the third exception (the parity tests record it); REST `POST /pro/manage_global_presets` through the existing runner; `uninstall.php` already removes the lock rows. The tool description tells the assistant how a preset is used (`modulePreset: ["<id>"]`) and that it restyles every module that cites it. `pro-addon/readme.txt` and `README.md` get entries in the existing 0.8.0 section; the add-on version stays 0.8.0.

## Testing

Unit (`make test-pro`, a new `GlobalPreset` shim that follows the source: additive import with de-duplication, a default preset created for a module with none, `save_data` replace-all, `count_presets`, `option_name`): argument typing and atomic refusals (unknown id, duplicate id, duplicate module+name, bad module, default without the flag, `module` on an update, empty attrs, 21 entries); attrs guard table (custom CSS in any `css` family, `freeForm`, `mainElement`, `<script`, an over-long or too-deep tree refused; a normal style tree accepted); capability enforced inside the accessor; fail-closed when Divi's class or a method is missing or throws; dry-run writes nothing and takes no lock; real run holds the lock before the first read; token misuse; skipped-when-changed-since; create stores the real shape (keys, `version`, `type`), default-preset side effect reported and undone, other presets untouched; update replaces attrs and sets `updated`, keeps `renderAttrs`; read-back classification; undo chain returns the data identical; legacy oddities (module `"0"`, an item with no `moduleName`) are carried untouched and refused as targets.
Live gate `make verify-pro-global-presets` (Docker, self-cleaning, dev site only): fingerprint the stored presets; dry run changes nothing; create a heading preset and prove through a throwaway PUBLISHED page that cites it that Divi emits the class and the preset's font-size rule; update it (new size) and prove the new rule renders; create a preset for a module that has none (skipped with a warning when none exists) and prove the default-preset side effect is reported and undone; custom CSS in attrs refused; undo the whole chain; the stored presets are byte-identical to the start; the page is deleted.

## Risks and decisions to confirm

- **Dead attributes:** the free tree lets an attribute Divi ignores be stored (harmless, confusing). The dry run shows exactly what will be stored; the style guide teaches the real shapes.
- **Updating a default preset restyles every module of that type**, hence the explicit flag and a warning in the preview.
- **Undo of a create removes the preset** and pages citing it lose its styling (same accepted trade-off as colors and variables).
- **`process_presets_for_import` also runs Divi's preset migrations** after saving (part of the import code); the read-back reports any change to other presets.
- **A Divi that changes the preset storage or these functions** fails closed (missing method or an unexpected shape refuses); the live gate is the early warning.

## Implementation notes

What the built code does where the design above was silent or changed during review (2026-10-06):

- **Safety-scanner convention.** `ProSafetyScanner` bans the literal Divi preset call and the raw option names in Pro source. `GlobalPresets` therefore holds the class name in a constant (`PRESET_CLASS`) and reaches every Divi method with `call_user_func( [ self::PRESET_CLASS, '<method>' ], ... )` behind `is_callable` guards. No literal `GlobalPreset` followed by two colons and no option name appears anywhere under `pro-addon/src/`; the scanner and its test are untouched.
- **New items are found by set difference.** Around each import the tool reads the item ids of that module before and after and takes `after minus before`. The format of Divi's id-mapping on a collision is not relied on; the new item is the one whose name and module match the entry, and any other new id is reported (see below).
- **`module_existed` is recorded per create** from the ids read just before THAT import, not from a value computed once for the call. Two creates for the same new module in one call therefore record the first as "the module did not exist" and the second as "it did", and the dry run flags `default_preset_created` on the first only.
- **A create's auto-created default preset is identified the same way** (the one new id that is not the requested preset, only expected when the module had no presets). Any further new id that is neither the match nor that one default is reported in `unexpected_changes` with reason `added_by_divi`.
- **Undo of a create is refused (`changed_since`) when anyone added a preset to a module the batch created** (the module entry is only removed when it holds exactly the batch's own items). The default preset Divi added is removed only while it is still exactly as Divi made it.
- **Divi's default presets can be attribute-less shells** (no `attrs` and no `styleAttrs` keys; seen live on a stock Divi 5.14 `divi/heading`). A preset is manageable when its module key is a normal `divi/...` key, its `moduleName` equals it, its `name` is a string, and `attrs` is an array or absent; `state()` counts an absent `attrs` as empty. Updating such a default needs `allow_default_presets` like any default. The live gate updates the real heading default, proves Divi renders it (Divi marks a module's default preset in the page with the fixed word `default`, not the id) and undoes it.
- **Undo of an update restores the previous `styleAttrs` and `attrs_absent`.** `styleAttrs` is kept apart from `attrs` because they can differ in a stored preset; the item records the previous `styleAttrs` tree and whether the `attrs` key was absent, so undoing an update of a shell removes `attrs` again. The undo sets `updated` to now, so a restored preset differs from the start by that stamp alone (the live gate allows exactly that and says so).
- **`group` (option-group) presets are untouched.** The whole `group` branch and every legacy entry travel through each save byte for byte; none is a valid target.
- **A real run with nothing to change answers without a token**, like the variables tool: the plan is empty, nothing is written, the answer lists the entries under `unchanged` and carries no `preview_token`. The token is only demanded (and spent, right before the first write) when a change is planned.
- **Partial failure is recorded.** If a read fails after updates were already saved, the batch is still recorded for what is stored and the error says how many changes were recorded (or that they could not be recorded). A preset that vanishes during the update save is reported as `not_stored`, not recorded.
- **Dev-site oddities the tool survives:** the legacy module entry keyed `0` and the preset without `moduleName` (`S2S Test Preset`) from the evidence doc are read, carried and saved back untouched (the live gate compares the whole stored data, legacy included, byte for byte), and a legacy id is refused as a target ("cannot be changed here").
- **Live gate:** `make verify-pro-global-presets` (Docker dev site only, self-cleaning). Beyond the checks in "Testing" it proves the multi-create undo, the shell default update and its render, every refusal for both dry and real runs, and that the batch table ends with the rows it started with.
