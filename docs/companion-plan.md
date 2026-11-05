# Divi MCP companion: Phase 0 discovery and plan

Branch: `feature/divi-mcp-companion` (local, nothing pushed). Status: Phase 0 only. No code has been written.
Everything below was read from this repository or from the WordPress core and Divi theme files inside the Docker dev site
(WordPress 7.1.2, Divi 5.15.1). Where something could not be verified here, it says so.

## Summary

1. The Abilities API exists in core, but only from WordPress 6.9. The plugin declares `Requires at least: 6.0`, so abilities must
   be registered conditionally and must never be required.
2. There is no shared tool definition today for the free plugin. MCP, REST and OpenAPI are three hand-written copies. The Pro
   add-on already has one definition per tool that drives all three. I propose a small shared catalog for the free plugin, pinned
   by a byte-for-byte snapshot test of today's `tools/list`, and abilities as a fourth consumer.
3. Our history snapshots only run inside our own write path. A universal capture needs one new hook (`pre_post_update`).
4. Divi 5.15.1 contains no Abilities or MCP code. Divi's MCP cannot be detected or collision-tested here. I propose detection by
   scanning registered abilities at run time, with a filterable list of namespaces.
5. Your brief says "30 current tools (16 free, 14 Pro)". That is the ChatGPT OpenAPI operation count. The real MCP tool count is
   21 free and 22 Pro (43). I will keep all 43 untouched and the OpenAPI at 30 operations.
6. Two items in Phase 1 need new, grounded work rather than wrapping: "Divi-default fallbacks" in `check-design`, and
   page-level contrast. See the open questions.

## 1. WordPress version and the Abilities API

Plugin headers: `Requires at least: 6.0`, `Requires PHP: 8.1`, `Tested up to: 7.1` (`wp-plugin/jhmg-ai-editor-for-divi-5.php:6-7`,
`wp-plugin/readme.txt:4-7`).

The Abilities API was added in WordPress 6.9.0 (`@since 6.9.0` in `wp-includes/abilities-api.php`). The dev site runs 7.1.2, which
has it, with extra hooks that are 7.1.0 only. Sites on 6.0 to 6.8 have none of it, so every ability registration sits behind
`function_exists( 'wp_register_ability' )` and a hook that only exists where the API does. The minimum WordPress version stays 6.0.
Raising it would break existing users, which constraint 1 forbids.

### Verified API surface (core source, `wp-includes/abilities-api.php` and `abilities-api/`)

| Item | Verified detail |
|---|---|
| Register an ability | `wp_register_ability( string $name, array $args ): ?WP_Ability` |
| Register a category | `wp_register_ability_category( string $slug, array $args ): ?WP_Ability_Category`, must exist before the abilities that use it |
| Other functions | `wp_unregister_ability`, `wp_has_ability`, `wp_get_ability`, `wp_get_abilities( $args )`, `wp_get_ability_categories`, `wp_has_ability_category`, `wp_get_ability_category`, `wp_unregister_ability_category` |
| Category init hook | action `wp_abilities_api_categories_init` (registering outside it triggers `_doing_it_wrong`) |
| Ability init hook | action `wp_abilities_api_init` |
| Ability name rule | `/^[a-z0-9-]+\/[a-z0-9-]+$/` (`class-wp-abilities-registry.php:86`): lowercase letters, digits and dashes, exactly one slash |
| Category slug rule | `/^[a-z0-9]+(?:-[a-z0-9]+)*$/`, so no slash |
| Required args | `label`, `description`, `category` (strings), `execute_callback`, `permission_callback` (callables) |
| Optional args | `input_schema`, `output_schema` (arrays), `meta` (`annotations.readonly|destructive|idempotent`, `public`, `show_in_rest`), `ability_class` |
| Schema format | JSON Schema, subset of Draft 4, validated by core |
| Callback signatures | both callbacks receive the single normalized input; execute returns any value or `WP_Error`; permission returns `bool` or `WP_Error` |
| Run order | `execute()`: `wp_ability_invoked` action, `wp_pre_execute_ability` filter, normalize input, validate input against the schema, `permission_callback`, `wp_before_execute_ability`, execute, validate output against `output_schema`, `wp_after_execute_ability` |
| Important order fact | input is validated BEFORE the permission check, and the permission callback receives the validated input. So a per-page `edit_post` check can live in the permission callback. |
| Important error fact | `wp_after_execute_ability` does NOT fire when the execute callback returns a `WP_Error`. Any "context set before, cleared after" scheme must not rely on it alone. |
| Output validation | a result that does not match `output_schema` becomes an error. Schemas must be accurate, so guide outputs should be loose strings or objects. |
| 7.1.0-only hooks | `wp_ability_invoked`, `wp_pre_execute_ability`, `wp_ability_normalize_input`, `wp_ability_permission_result`, `wp_ability_execute_result`. `wp_before_execute_ability` and `wp_after_execute_ability` are 6.9.0. |

### Exposure over REST

Core registers `wp-abilities/v1` (`class-wp-rest-abilities-v1-*-controller.php`, loaded from `rest-api.php:421-426`):

- list: `/wp-abilities/v1/abilities`, categories: `/wp-abilities/v1/categories`
- run: `/wp-abilities/v1/abilities/{name}/run`
- An ability is exposed only if `meta.show_in_rest` is true. It defaults to the value of `meta.public`, which defaults to false.
  So every ability I register must set `meta.public = true` (or `show_in_rest`), otherwise nothing can call it over REST.
- HTTP method follows the annotations: `readonly` requires GET, `destructive` plus `idempotent` requires DELETE, everything else
  POST (`validate_request_method`). Input is the `input` query parameter for GET and DELETE, and `input` in the JSON body for POST.
  Core coerces GET string input to the schema types in 7.1.
- Authentication is WordPress's own (cookie plus nonce, application passwords). It is NOT our API key or our OAuth tokens, which
  only authenticate our own `/mcp` and REST routes. Abilities therefore run as whichever user WordPress authenticated, and the
  permission callbacks use `current_user_can`.

### Exposure to MCP adapters (NOT verified here)

Core has no MCP code. Whether an MCP adapter lists an ability depends on that adapter. The official WordPress MCP adapter plugin
reads its own meta flag (I believe `meta.mcp.public`) but that plugin is not installed here, so I cannot confirm the key name, and
Divi's MCP may use its own mechanism. I will set `meta.public` and `show_in_rest` (verified) and add the adapter flag only after you
confirm the name or I can read the adapter source. See open question 3.

## 2. How tools are registered today

| Layer | Where | Shape |
|---|---|---|
| MCP | `wp-plugin/src/McpHandler.php`: `onToolsList` (lines 125-373, one hand-written array), `onToolsCall` (lines 375-412, a `match`), and a `jhmg_aied_mcp_tools` / `jhmg_aied_mcp_call` filter pair that the Pro add-on uses | JSON-RPC, tool methods return `WP_REST_Response` wrapped in JSON-RPC |
| REST | `wp-plugin/src/RestController.php`: 20 `register_rest_route` calls under `ai-editor-divi5/v1` | one handler per route |
| OpenAPI | `wp-plugin/src/OpenApiSpec.php`: hand-written spec with 16 operations | static array |
| Pro | `pro-addon/src/ProTool.php` (`name`, `capability`, `definition`, `run`), `ToolRegistry`, `ToolRunner`, `Rest/ProOpenApi` | ONE definition per Pro tool drives MCP, REST and OpenAPI |

So the answer to "does one shared definition already drive all three" is: Pro yes, free no. In the free plugin the three transports
are kept in step by lockstep tests (`*LockstepTest.php`, `OpenApiSpecTest`, `McpProtocolTest`).

### Proposal: one registry, abilities as an extra consumer

1. Add a snapshot test first: the exact JSON of `tools/list` (and of the OpenAPI spec) as it is today, stored as a golden file.
2. Extract the 21 tool definitions (name, description, input schema) out of `onToolsList` into a `ToolCatalog` class. `onToolsList`
   reads from it and must produce byte-identical output (the snapshot test proves it). `onToolsCall` and the REST routes are not
   touched.
3. `AbilityCatalog` maps a subset of the catalog to abilities: ability name, label, a "when to use it" description, annotations,
   capability rule. It reuses the tool's input schema.
4. Execution: the write tools (`update_page_layout`, `edit_page_content`, `create_page`, restore) carry approval mode, dry run,
   title-only saves and history. Re-implementing that a third time would risk divergence. I propose a small public
   `McpHandler::callTool( string $name, array $args ): array` that runs the existing private tool method and unwraps the JSON-RPC
   payload, so abilities and MCP execute the same code. The read-only guide tools are one-line calls to pure classes (`StyleGuide`,
   `SectionRecipes`, `SiteGuide`, `LandingGuide`, `ImageGuide`) and need no unwrapping.
5. Pro: `ProTool` already has everything an ability needs. A `ProAbilities` class wraps `ToolRunner::run` for the Pro tools.

This changes no name, parameter or behavior of the existing 43 tools. If you would rather not touch `McpHandler` at all, the
fallback is to leave step 2 out and define the ability schemas separately, at the cost of two copies drifting.

## 3. Page history today, and what a universal capture needs

Today: `HistoryService::write()` (`wp-plugin/src/HistoryService.php:25`) reads the current `post_content`, snapshots it through
`PageHistory::record` into the table `{prefix}ai_editor_divi5_history`, then calls `wp_update_post`. It is the only writer, called by
`update_page_layout`, `edit_page_content`, the REST PUT and edit routes, restore, and Pro writes. So it captures only our own saves.
The only save-related hook in the free plugin is `delete_post` (cleanup).

The table already has what a source label needs: `tool varchar(50)`, `actor bigint`, `label varchar(255)` (`HistoryTable.php:45-58`).
No schema change is required: `tool` can hold the coarse source and `label` the application password name. Limits stay as they are
(10 snapshots per page, 512 KB each, 768 KB per page). `PageHistory::record` already skips duplicates by hash.

Hooks that see saves from any source (all verified in `wp-includes/post.php`):

| Hook | Use |
|---|---|
| `pre_post_update( $post_id, $data )` (line 4993) | fires before the database write while `get_post()` still returns the OLD content. This is the capture point. |
| `wp_insert_post_data` (line 4978) | gives only the new data, no old content. Not suitable for capture. |
| `post_updated( $id, $after, $before )` (line 5255) | after the write; has before and after, but too late if the write is large and we want to skip unchanged content cheaply. Useful for strict-mode validation (Phase 5). |
| `wp_after_insert_post` (line 6013) | after save, with `$post_before`. Alternative for validation. |

Source detection (all verified except Divi's own paths):

- Ours: a request-scoped marker set inside `HistoryService::write`, so a universal hook does not snapshot a save we already snapshot.
- Application password: action `application_password_did_authenticate( $user, $item )` (`user.php:497`), where `$item['name']` is the
  application password's name. The name is not a credential. We never read the password.
- Another plugin's ability, including Divi's: `wp_before_execute_ability( $name )` gives the ability name for the duration of the call.
  This only works if Divi's MCP runs abilities through `WP_Ability::execute`, which I cannot verify yet.
- REST: `wp_is_rest_endpoint()` / `REST_REQUEST`.
- Builder: Divi's builder save path was NOT examined in this phase. See open question 5. Builder saves default to off in your brief.
- Otherwise `unknown`.

Performance: `pre_post_update` runs on every post update. The handler returns immediately unless the post type is a page or Divi Project,
the content contains `<!-- wp:divi/`, the source type is enabled, and the hash differs from the newest snapshot. `get_post` is served
from the object cache.

Two things to be aware of. A noisy agent making many writes evicts older snapshots (10 per page), including ours. And snapshots from
other sources store the page's previous content, which is a privacy point for the readme.

## 4. Detecting Divi's MCP

In this environment: Divi 5.15.1 contains no `wp_register_ability`, no `wp_abilities_api_init` and no reference to "mcp" in its PHP files.
Elegant Themes' MCP is not released, so its ability names, namespace, and whether it ships inside Divi or as a separate plugin are unknown.

Proposed detection (survives the release, never assumes names):

1. Only when `function_exists( 'wp_get_abilities' )`.
2. Late, at read time (admin render, `check-design` response), not at registration: scan `wp_get_abilities()` for names whose prefix is in a
   list, default `divi`, `divi-ai`, `et`, `elegant-themes`, filterable with `jhmg_aied_divi_mcp_namespaces`.
3. Result is a cached boolean plus the count of matching abilities, shown in the Dashboard. Never an error if absent.
4. Never wrap, unregister or filter another plugin's abilities. We only read the list.

The reserved-namespace test (constraint 6) forbids our names from starting with those prefixes. Our names cannot collide by construction,
because abilities are namespaced `jhmg-ai-editor/...`. When Divi's MCP ships, I will re-check its real names and update the default list.

## 5. Classification of every current tool

Counts: 21 free MCP tools, 22 Pro MCP tools. OpenAPI: 16 free plus 14 Pro operations (30, ChatGPT's limit). Nothing is deprecated here.

### Free (21)

| Tool | Proposed | Notes |
|---|---|---|
| `get_style_guide` | keep + ability `get-style-guide` | unique |
| `get_landing_guide` | keep + ability `get-landing-guide` | unique |
| `get_site_guide` | keep + ability `get-site-guide` | unique |
| `get_image_guide` | keep + ability `get-image-guide` | unique |
| `get_section_recipes` | keep + ability `get-section-recipes` | needs `density` and `persuasion stage` filters; today it has `role`, `layout`, `mood`, `style_kit`, `design_language` |
| `validate_layout` | keep + ability `validate-layout` | unique |
| `list_page_history` | keep + ability `list-page-history` | unique |
| `get_page_history_entry` | keep + ability `get-page-history-entry` | unique |
| `restore_page_version` | keep + ability `restore-page-version` | unique |
| `update_page_layout` | keep + ability `update-page-layout` | overlaps with Divi MCP page writes: future deprecation candidate |
| `edit_page_content` | keep + ability `edit-page-content` | surgical find and replace: unique |
| `create_page` | keep + ability `create-page` | overlaps with Divi MCP: future deprecation candidate |
| `list_divi_pages` | keep + ability (proposed) | needed for "usable without Divi MCP"; likely overlaps Divi MCP |
| `get_page_layout` | keep + ability (proposed) | same; has outline view, which is a token saver |
| `list_global_styles` | keep, ability optional | read-only; Divi MCP creates variables and presets, so likely overlaps: future deprecation candidate |
| `get_site_structure` | keep | MCP and REST only; partly unique (ACF field groups, components) |
| `get_custom_fields` | keep | MCP and REST only |
| `list_media_images` | keep | read-only |
| `list_products`, `get_product` | keep | read-only WooCommerce |
| `html_to_divi` | keep | unique, deterministic; MCP and REST only |

### Pro (22)

| Tool | Proposed | Notes |
|---|---|---|
| `audit_site` | keep + ability `audit-site` (extended) | unique |
| `find_replace_site` | keep + ability `find-replace-site` | unique (preview token) |
| `list_batches`, `undo_batch` | keep + abilities | unique; basis for Phase 4 batch undo |
| `switch_design` | keep, ability candidate | unique (restyle between design languages and kits) |
| `bulk_adjust_prices`, `update_products`, `create_product` | keep | WooCommerce writes; Divi MCP unlikely to cover |
| `set_custom_fields`, `set_custom_css`, `propose_php_snippet` | keep | unrelated to Divi MCP |
| `set_front_page`, `set_primary_menu`, `publish_page`, `build_site` | keep | partial overlap with Divi multi-page build: future deprecation candidate for `build_site` |
| `list_theme_layouts`, `update_theme_layout`, `edit_theme_layout`, `manage_theme_template` | keep | Divi MCP edits Theme Builder too: future deprecation candidates |
| `manage_global_colors`, `manage_global_variables`, `manage_global_presets` | keep | Divi MCP creates variables and presets: future deprecation candidates |

## 6. Phase plan with risks

| Phase | Work | Main risk |
|---|---|---|
| 1 | snapshot test, `ToolCatalog`, `AbilityCatalog`, conditional registration, 7 guide/validation abilities plus history and write abilities | output schemas that are too strict fail real calls; keep them loose and test each |
| 1b | `check-design` and `get-variety-advice` | see open questions 1 and 2 |
| 2 | `pre_post_update` capture, source settings, restore, dashboard column | double snapshots with our own writes (handled by a request-scoped marker) |
| 3 | admin and readme copy | readme must state the 6.9 requirement for abilities |
| 4 | Pro abilities, batch grouping, `design-review-site` | batches are recorded tool by tool today; grouping history rows into batches after the fact needs a design (question 6) |
| 5 | strict mode, flag only | proposed approach below |
| 6 to 7 | docs and tests | the suites are large (free 6,384, Pro 2,919); free needs `php -d memory_limit=-1` |

### Phase 5 proposal (for approval before any code)

Non-blocking flag only. On `wp_after_insert_post` (after the save, so nothing is delayed or rolled back), for requests authenticated by an
application password or arriving over REST, run `LayoutGate::check` in report mode on the saved content. If it fails, record
`{post_id, source, codes, time}` in a capped list (last 50) and show it in a Pro admin notice and on the Pro tab. The save is never
blocked, changed or reverted, and there is no per-request cost on saves of non-Divi content. A blocking design (for example filtering
`wp_insert_post_data`, or restoring the previous content from the Phase 2 snapshot) would be possible but it interferes with another tool's
write and its error handling, so I will not build it without your explicit approval.

## 7. Things I found that change the brief

1. "30 current tools" is the OpenAPI count. Real counts are 21 free and 22 Pro MCP tools. Regression tests will cover all 43 MCP tools and
   the 30 OpenAPI operations.
2. Phase 1 lists no ability for `list_divi_pages` or `get_page_layout`, yet "fully usable without Divi's MCP" and `update-page-layout`
   depend on them. I propose adding them.
3. `check-design` asks for "Divi-default fallbacks (grey placeholders, blue buttons, pale tab bars)". Nothing in the repo detects these.
   The project rule is that no Divi attribute shape or default comes from memory. Each default must come from a real render on the dev
   site (the effect probe, `make verify-shapes`), with the evidence committed. That is real new work, separate from wrapping.
4. `check-design` also asks for contrast on any page. Contrast is proven for recipes and for the converter's output, but there is no
   page-level checker over arbitrary Divi markup. A deterministic one is feasible (resolve text and background colors through design
   tokens and global colors), but it can only judge what it can resolve. Unresolved cases would be reported as "not checked", never as
   passes.
5. `get-variety-advice` "suggested recipes": `DesignVariety` returns warnings but no recipes. I would add a fixed, deterministic mapping
   from warning type to recipe roles and layouts, tested like the guide pointers.
6. `WpOrgComplianceTest` asserts exactly 21 tools and the string "(21 tools)" in the readme. Abilities are not MCP tools, so those stay true,
   but the readme change needs a new assertion for the abilities statement. No existing rule is loosened.
7. Phase 2 stores page content from saves we did not make. The readme must say so, and the setting defaults must match your brief
   (agent and REST on, builder off).

## 8. Open questions for you

1. Default fallbacks: may I run the effect probe on the dev site to establish which grey, blue and pale values Divi 5.15.1 actually falls
   back to, and only check for those? Or should `check-design` ship without that check in the first version?
2. Page-level contrast: ship a conservative checker that reports "could not resolve" where it cannot, or defer contrast to a later version?
3. MCP adapter exposure: do you want me to flag abilities for the official WordPress MCP adapter? I need either the adapter source or
   your confirmation of the meta key. Without it abilities are reachable over `wp-abilities/v1` only.
4. Execution reuse: agree to a public `McpHandler::callTool` so abilities and MCP run identical code, and to the `ToolCatalog` extraction
   guarded by a golden `tools/list` snapshot? If not, the abilities get separate schemas and thin handlers, and may drift.
5. Builder saves: Divi 5's builder save path (REST, admin-ajax, or its own endpoint) is not examined. May I inspect it on the dev site
   before Phase 2? It decides whether "REST on, builder off" can be separated reliably. If it cannot, the safest default is to capture
   only application-password and ability-initiated writes.
6. Pro batch undo for other sources: build "virtual batches" from history rows at undo time (same actor and source within a time window,
   plus explicit start and end abilities), or record batches as the writes happen? The first needs no new table. I recommend the first.
7. Pro ability names: use the free prefix `jhmg-ai-editor/` for everything, or `jhmg-ai-editor-pro/` for Pro abilities? Your brief has
   the Pro category as `jhmg-ai-editor-pro`. I recommend the matching prefix `jhmg-ai-editor-pro/audit-site`.
8. Minimum WordPress: confirm that it stays at 6.0, with abilities available only on 6.9 and later.
9. Version number: current free version is 4.16.0. I will use 4.17.0 and leave the Pro version bump to the Pro phase. Confirm.
10. Divi trademark wording: "Works alongside the official Divi MCP" is descriptive. Do you want a disclaimer line ("not affiliated with
    Elegant Themes") in the readme?

## Verification record

- Core files read: `wp-includes/abilities-api.php`, `abilities.php`, `abilities-api/class-wp-ability.php`,
  `class-wp-abilities-registry.php`, `class-wp-ability-categories-registry.php`, `rest-api/endpoints/class-wp-rest-abilities-v1-run-controller.php`,
  `post.php` (hook lines), `user.php` (application password hooks).
- Divi 5.15.1 theme PHP searched for `wp_register_ability`, `wp_abilities_api_init` and "mcp": no matches.
- Repo files read: `McpHandler.php`, `RestController.php`, `OpenApiSpec.php`, `HistoryService.php`, `HistoryTable.php`, `PageHistory.php`,
  `ApiKey.php`, `DesignVariety.php`, `WpOrgComplianceTest.php`, and in the Pro add-on `ProTool.php`, `ToolRegistry.php`, `Tools.php`,
  `Batch/*`.
- Not verified: any MCP adapter's meta key, Divi's MCP names and execution path, Divi's builder save path.

## Outcome (built on this branch)

Your answers to section 8, and what was built. Decisions I took from my own recommendation are marked (R).

| # | Question | Decision |
|---|---|---|
| 1 | Divi's default fallbacks | Probed once on the dev site (`scripts/probe-divi-defaults.cjs`). Found and used: the builder's grey placeholder (`#EBEBEB` SVG data URI, certain), the stock button (white label, transparent fill, 2px white border) and the stock tab bar (`#f4f4f4`), both "possible" and silenced when the site has a preset for the module. |
| 2 | Page-level contrast | Built, conservative: only definite failures, everything else "not checked". |
| 3 | MCP adapter key | Read from WordPress/mcp-adapter v0.7.1: `meta.mcp.public` (falls back to `meta.public`), `meta.mcp.type`. Both set. |
| 4 | Shared catalog and `callTool` | Done; `tools/list` and the OpenAPI spec are pinned byte for byte. |
| 5 | Builder saves | Distinguishable: Divi's builder saves through the REST route `/divi/v1/sync-to-server` (all of Divi's own interface is under `/divi/v1/`). Builder is off by default; anything unmatched is labelled "unknown" and kept. |
| 6 | Pro batches | Virtual batches from history rows at undo time; no new table. |
| 7 | Pro ability names | `jhmg-ai-editor-pro/`. |
| 8 | Minimum WordPress and version | Minimum stays 6.0. Latest released version is 4.16.0 (header, constant and readme agreed), so this branch is 4.17.0. |
| 9 | (R) Version number | 4.17.0 in the header, constant, readme and changelog. The Pro version is not bumped (a release decision). |
| 10 | (R) Disclaimer | The readme already had a non-affiliation sentence; a test allows only one, so no second was added. |

Things that differ from the plan:

* The running ability is found by reading the call stack, not by remembering a name between `wp_before_execute_ability` and
  `wp_after_execute_ability`. The live gate showed the remembered name goes stale when an ability fails (core does not fire the
  "after" hook then).
* "Changed afterwards" for a batch undo compares history version ids (exact) and the page's modified time (for saves that keep
  no snapshot, such as the builder when it is switched off). History times have one-second resolution.
* The companion Pro features need free 4.17.0. They are guarded at run time and not listed in `Compat::REQUIRED_CLASSES`, so a
  Pro add-on running beside an older free plugin keeps every existing tool.

## Mega menu (approved 2026-11-05)

The mega menu branch (`feat/pro-megamenu`) was already part of this branch's history, so there was nothing to merge. Added on top:
`manage_mega_menu` as the 23rd Pro tool (MCP and REST only; OpenAPI stays at 16 free + 14 Pro = 30), the abilities `get-mega-menu` and
`set-mega-menu`, goldens for the new surface (`pro-addon/tests/golden/`), a fix so agent batches list only page and Project rows (a
header edit is a recorded batch undone by `undo_batch`), and an uninstall fix for the mega menu's option and transients.
