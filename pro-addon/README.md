# pro-addon/ — staging area (not shipped)

This folder holds the source that was removed from the WordPress.org plugin
(`wp-plugin/`) in 4.0.0 so it is not lost. It is a **staging copy**, not a plugin:

- **Not shipped** — it is never part of `jhmg-ai-editor-for-divi-5.zip`.
- **Not built** — nothing here is loaded, autoloaded or activated.
- **Not in PHPUnit** — `phpunit.xml` only scans `tests/`; the tests in `pro-addon/tests/`
  are kept for reference and are not run. Their `require_once` paths point at
  `pro-addon/src/`, but they still expect the free plugin's old namespace, constants and
  WordPress shims from the main `tests/bootstrap.php`; they must be re-wired (own
  bootstrap, own `phpunit.xml`) when the add-on is built.

## What is in here

| Path | What it was |
|---|---|
| `src/Licensing.php`, `src/Licensing/LicenseClient.php` | licence client (activation, validation, status notices) |
| `src/MenuBuilder.php` | backing code for `set_primary_menu` |
| `src/CustomCss.php` | backing code for `set_custom_css` (managed Additional CSS block) |
| `src/PhpProposals.php` | backing code for `propose_php_snippet` (stored for human review, never executed) |
| `tests/LicensingTest.php`, `tests/PhpProposalsTest.php` | their tests |
| `staged-4.3.0/` | the 4.3.0 header/footer editing (`ThemeLayouts`, the layout half of `EditTarget`, their tests, `verify-theme-layouts.php`, and `4.3.0-free-plugin.diff`, the exact hunks that were in the free plugin's handlers, OpenAPI and guides). Removed from the free plugin in the free re-cut because it moves to the Pro add-on. Copies of the 4.4.0 tree; not run, not shipped. |

Also removed from the plugin and to be rebuilt in the add-on: the `set_front_page`,
`set_primary_menu`, `set_custom_css` and `propose_php_snippet` MCP/REST/OpenAPI tools,
and the licence/upgrade/code-proposal admin UI.

`staged-4.3.0/tests/` need their own bootstrap (they use `tests/WpRestShims.php` and the other shims of the main suite) before they can run; `phpunit.xml` only scans `tests/`, so they are not run today.

## Contract

The future Pro add-on is a **separate plugin**. It will extend the free plugin using only
the `jhmg_aied_*` hooks that Task 3 of the 4.0.0 plan adds to the free plugin. It must not
need any other change to the free plugin. Its own spec and plan will follow; this folder is
only the source material.
