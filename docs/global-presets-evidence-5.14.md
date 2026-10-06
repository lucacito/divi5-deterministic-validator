# Writing module presets on Divi 5.14: live evidence (Docker dev site, 2026-10-06)

Spike for a possible Pro `manage_global_presets` tool. Script: `scripts/spike-global-presets.php` (dev sites only; snapshots the stored presets option, adds throwaway presets through Divi's own functions, renders throwaway published pages, removes everything, restores the option byte for byte and proves it). No product code changed.

## Stored shape (Divi 5.14; this dev site: 105 module presets in 15 module entries, no group presets)

`{"module": { "<divi/module>": { "default": "<preset id>", "items": { "<id>": { id, moduleName, name, attrs, styleAttrs, [renderAttrs], version, type: "module", created, updated } } } }, "group": { ... }}` (`group` absent here). `attrs` and `styleAttrs` carry the same style tree for a plain style preset; `renderAttrs` holds html/script attrs (e.g. heading level). `version` is whatever the writer passes (the builder writes the current Divi version, here `5.14.0`; older presets keep `5.8.1`, `5.0.0-public-beta.1` and so on). `created` and `updated` are unix timestamps.
This site also holds legacy oddities a real tool must survive: a module entry keyed `"0"` and a preset (`S2S Test Preset`) with no `moduleName`.

## Divi's own additive write path

`GlobalPreset::process_presets_for_import( array $presets, bool $auto_save )` (the import code): converts D4 to D5 if needed, MERGES the given `{module:{<name>:{default,items:{...}}}}` into the stored presets (de-duplicating), creates a default preset for a module that has none, saves through `save_data()` (which clears Divi's caches) and returns `{presets, preset_id_mappings, defaultImportedModulePresetIds, defaultImportedGroupPresetIds}`.

| Probe | Result |
|---|---|
| Add one heading preset (fresh uuid, shape copied from a real preset, `57px` font size and `39px` bottom margin) | Stored; total 105 to 106; **nothing else changed** (a full diff of the data is empty); the module's `default` unchanged; no id remapping (`preset_id_mappings` empty); Divi set `created` and `updated` itself |
| A page whose heading has `modulePreset: [<new id>]` | The heading gets the class `preset--module--divi-heading--<id>` and Divi emits `.preset--module--divi-heading--<id>{margin-bottom:39px!important}` and the `font-size:57px` rule for h1 to h6. A heading without the preset and one with an unknown id get no such rule (the unknown id's class is present but defines nothing) |
| The SAME import again (identical data) | Idempotent: data identical, count unchanged |
| The same id with DIFFERENT attrs and name via import | **Not an update:** the stored original is unchanged (name and size) and the total rose by one. An import only adds |
| The same change by saving the current data with the item replaced (`GlobalPreset::save_data()`) | Updated (name and size changed). Divi does NOT touch `updated`: the writer must set it |
| Import a preset for a module with NO presets yet (`divi/image`) | The module entry appears AND Divi creates a **default preset** for it (`Image 1`, version `5.14.0`), so the total rose by two. `defaultImportedModulePresetIds` stayed empty. An undo must remove both |
| Pass `version` = the current Divi version | Kept as passed |
| Removal: save the current data without the added item(s) | The data equals the pre-probe data exactly and the raw option is byte-identical to the snapshot |
| A preset whose attrs contain `css.freeForm` / `css.mainElement` (custom CSS) | Divi STORES it in `styleAttrs`; my attempt did not render it into the page CSS (the path I used may simply not be the live one), so it is not proven harmless |

## What this means for a Pro tool

1. **Creating a preset is additive and safe through Divi's own import function**, unlike colors and variables (their save replaces the whole list): existing presets cannot be erased by a create. Reading before and after still detects unexpected changes.
2. **Update and removal need `save_data()` with the complete, current data** (replace-all, so the same lock, pre-flight and read-back as the colors and variables tools), and the writer must maintain `updated` and refuse legacy oddities it cannot represent faithfully.
3. **Importing for a module without presets also creates a default preset.** The tool must report it, record it in the batch and remove it on undo.
4. **Custom code can be stored in a preset.** Presets bypass `LayoutGate`, so a preset write must run the same custom-CSS/JS policy (`CodePolicy`, the `freeForm`/`mainElement` family and the style-value guard) on the attrs before anything is saved, by validating the attrs as the attributes of a synthetic block of that module.
5. A cited preset id is just `modulePreset: ["<id>"]`, which the guides already teach; the unknown-id advisory already exists.
6. Presets restyle every page that uses them (and a module's default preset restyles every module of that type without an explicit preset), so the blast radius is site-wide: dry run, preview token, one batch, undo, lock, like the other global tools.
