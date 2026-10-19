.PHONY: up down clean test test-pro build-pro verify-pro-skeleton verify-pro-settings verify-pro-custom-fields verify-pro-create-product verify-pro-update-products verify-pro-bulk-adjust-prices verify-pro-theme-layouts verify-pro-theme-templates verify-pro-global-colors verify-pro-global-variables verify-pro-global-presets verify-pro-site-tools verify-pro-rest verify-pro-build-site verify-pro-licence-tiers validate install shell-wp shell-wpcli export-layouts app-password schema-gap verify-modules verify-history verify-dry-run verify-global-styles verify-site-structure verify-acf-custom-fields verify-custom-fields verify-project-layouts verify-page-title verify-products verify-oauth verify-test-connection verify-html-to-divi verify-guide-shapes verify-shapes verify-recipes verify-kits verify-components verify-style-values card-report divi-currency

# ---------------------------------------------------------------
# up — bootstrap the full environment (idempotent)
# ---------------------------------------------------------------
up:
	@bash scripts/bootstrap.sh

# ---------------------------------------------------------------
# down — stop containers (data preserved)
# ---------------------------------------------------------------
down:
	docker compose down

# ---------------------------------------------------------------
# clean — stop containers AND delete volumes (destructive!)
# Prompts for confirmation.
# ---------------------------------------------------------------
clean:
	@echo "WARNING: This will destroy all database and WordPress data."
	@echo "Named volumes to be removed: divi5val_db, divi5val_wp"
	@printf "Type 'yes' to confirm: "; read answer; \
	if [ "$$answer" = "yes" ]; then \
		docker compose down -v; \
		echo "Volumes removed."; \
	else \
		echo "Aborted."; \
	fi

# ---------------------------------------------------------------
# install — install PHP/Composer dependencies locally
# ---------------------------------------------------------------
install:
	composer install

# ---------------------------------------------------------------
# test — run full PHPUnit suite; exits non-zero on any failure
# ---------------------------------------------------------------
test: install
	@bash scripts/self-test.sh

# ---------------------------------------------------------------
# test-pro — run the Pro add-on's own PHPUnit suite
# ---------------------------------------------------------------
test-pro:
	@./vendor/bin/phpunit -c pro-addon/phpunit.xml

# ---------------------------------------------------------------
# build-pro — build dist/jhmg-ai-editor-for-divi-5-pro.zip (sold from divi5lab.com; git-ignored)
# ---------------------------------------------------------------
build-pro:
	@bash scripts/build-pro-zip.sh

# ---------------------------------------------------------------
# verify-pro-skeleton — real-site check of the Pro add-on (Docker site; the free and Pro plugins must be active)
# ---------------------------------------------------------------
verify-pro-skeleton:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-skeleton.php --user=1

# ---------------------------------------------------------------
# verify-pro-licence-tiers — real-site check of the tier display, site limit and expiry against a LOCAL layoutlab licence server
#   make verify-pro-licence-tiers STAGE=limit|agency|expired|cleanup LICENCE_BASE=http://host.docker.internal:3010
# (self-cleaning; dev site only; the free and Pro plugins must be active; see the script header)
# ---------------------------------------------------------------
verify-pro-licence-tiers:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-licence-tiers.php $(STAGE) $(LICENCE_BASE) --user=1

# ---------------------------------------------------------------
# verify-pro-settings — real-site check of the Pro settings tools and batch undo (self-cleaning; free + Pro plugins must be active)
# ---------------------------------------------------------------
verify-pro-settings:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-settings.php --user=1

# ---------------------------------------------------------------
# verify-pro-custom-fields — real-site check of set_custom_fields (self-cleaning; dev site only; free + Pro plugins active; ACF checks run when the free ACF plugin is active)
# ---------------------------------------------------------------
verify-pro-custom-fields:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-custom-fields.php --user=1

# ---------------------------------------------------------------
# verify-pro-create-product — real-site check of the Pro tool create_product (self-cleaning; dev site only; free + Pro plugins and WooCommerce active; it records several batches, so on a site with batches it evicts the oldest real ones)
# ---------------------------------------------------------------
verify-pro-create-product:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-create-product.php --user=1

# ---------------------------------------------------------------
# verify-pro-update-products — real-site check of the Pro tool update_products (self-cleaning; dev site only; free + Pro plugins and WooCommerce active; it records about 22 batches, so it evicts every batch row already on the dev site)
# ---------------------------------------------------------------
verify-pro-update-products:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-update-products.php --user=1

# ---------------------------------------------------------------
# verify-pro-bulk-adjust-prices — real-site check of the Pro tool bulk_adjust_prices (self-cleaning; dev site only; free + Pro plugins and WooCommerce active; it records many batches, so it evicts EVERY batch row already on the dev site)
# ---------------------------------------------------------------
verify-pro-bulk-adjust-prices:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-bulk-adjust-prices.php --user=1

# ---------------------------------------------------------------
# verify-pro-theme-layouts — real-site check of the Pro header/footer tools through the real registry (self-cleaning; free + Pro plugins must be active; dev site only: it evicts old batch rows)
# ---------------------------------------------------------------
verify-pro-theme-layouts:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-theme-layouts.php --user=1

# ---------------------------------------------------------------
# verify-pro-theme-templates — real-site evidence gate for manage_theme_template (self-cleaning; free + Pro plugins must be active; dev site only)
# ---------------------------------------------------------------
verify-pro-theme-templates:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-theme-templates.php --user=1

# ---------------------------------------------------------------
# verify-pro-global-colors — real-site evidence gate for manage_global_colors (self-cleaning; free + Pro plugins must be active; dev site only)
#   (the probe page is fetched through the Docker network; set AIED_LOOPBACK_BASE if http://wordpress is not reachable)
# ---------------------------------------------------------------
verify-pro-global-colors:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-global-colors.php --user=1

# ---------------------------------------------------------------
# verify-pro-global-variables — real-site evidence gate for manage_global_variables (self-cleaning; free + Pro plugins must be active; dev site only)
# ---------------------------------------------------------------
verify-pro-global-variables:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-global-variables.php --user=1

# ---------------------------------------------------------------
# verify-pro-global-presets — real-site evidence gate for manage_global_presets (self-cleaning; free + Pro plugins must be active; dev site only)
#   (the probe page is fetched through the Docker network; set AIED_LOOPBACK_BASE if http://wordpress is not reachable)
# ---------------------------------------------------------------
verify-pro-global-presets:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-global-presets.php --user=1

# ---------------------------------------------------------------
# verify-pro-site-tools — real-site check of find_replace_site and audit_site through the real registry (self-cleaning; free + Pro plugins must be active; dev site only: it evicts old batch rows)
# ---------------------------------------------------------------
verify-pro-site-tools:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-site-tools.php --user=1

# ---------------------------------------------------------------
# verify-pro-rest — real-site check of the Pro REST routes and the live OpenAPI spec (read-only; free + Pro plugins must be active)
# ---------------------------------------------------------------
verify-pro-rest:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-rest.php --user=1

# ---------------------------------------------------------------
# verify-pro-build-site — real-site check of build_site (build, launch, anonymous menu, undo; self-cleaning; free + Pro plugins must be active; dev site only: it evicts old batch rows)
# ---------------------------------------------------------------
verify-pro-build-site:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-build-site.php --user=1

# ---------------------------------------------------------------
# validate — run the validator against an arbitrary JSON file
# Usage: make validate FILE=fixtures/valid/my-layout.json
# ---------------------------------------------------------------
validate:
ifndef FILE
	$(error FILE is required. Usage: make validate FILE=path/to/layout.json)
endif
	@php scripts/validate.php "$(FILE)"

# ---------------------------------------------------------------
# export-layouts — capture real Divi 5 layouts from the running env
# ---------------------------------------------------------------
export-layouts:
	@bash scripts/export-layouts.sh

# ---------------------------------------------------------------
# app-password — create a WordPress Application Password for REST/API testing
# Prints the credentials to copy into your MCP config / .env
# ---------------------------------------------------------------
app-password:
	@bash scripts/create-app-password.sh

# ---------------------------------------------------------------
# schema-gap — which Divi modules does the validator not know yet?
# ---------------------------------------------------------------
schema-gap:
	@bash scripts/schema-gap.sh

# ---------------------------------------------------------------
# verify-modules — render candidate modules on the real Divi and record evidence
# ---------------------------------------------------------------
verify-modules:
	@bash scripts/verify-modules.sh

# ---------------------------------------------------------------
# verify-history — real-database check of the undo-history table (Docker site)
# (php -d memory_limit=1G: the wpcli default 128M dies loading the Divi theme)
# ---------------------------------------------------------------
verify-history:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-history-table.php --user=1

# ---------------------------------------------------------------
# verify-dry-run — real-site check of dry_run on the REST and MCP handlers (Docker site)
# ---------------------------------------------------------------
verify-dry-run:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-dry-run.php --user=1

# ---------------------------------------------------------------
# verify-global-styles — real-site check of list_global_styles and the style-reference advisory (Docker site; read-only; one throwaway draft page)
# ---------------------------------------------------------------
verify-global-styles:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-global-styles.php --user=1

# ---------------------------------------------------------------
# verify-site-structure — real-site check of get_site_structure, approval mode and the MCP protocol fixes (Docker site; two throwaway draft pages; restores the setting)
# ---------------------------------------------------------------
verify-site-structure:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-site-structure.php --user=1

# ---------------------------------------------------------------
# shell helpers
# ---------------------------------------------------------------
shell-wp:
	docker compose exec wordpress bash

shell-wpcli:
	docker compose exec wpcli bash

# ---------------------------------------------------------------
# verify-acf-custom-fields — real-site check of the custom-field advisory (needs the free ACF plugin active on the dev site, see the script header; creates and deletes a field group and throwaway pages)
# ---------------------------------------------------------------
verify-acf-custom-fields:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-acf-custom-fields.php --user=1

# ---------------------------------------------------------------
# verify-custom-fields — real-site check of get_custom_fields, custom field discovery and the plain-meta render (Docker site; throwaway pages and a contributor user, always deleted)
# ---------------------------------------------------------------
verify-custom-fields:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-custom-fields.php --user=1

# ---------------------------------------------------------------
# verify-project-layouts — real-site check of editing Divi Project layouts (Docker site; a throwaway draft Project and blog post, always deleted)
# ---------------------------------------------------------------
verify-project-layouts:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-project-layouts.php --user=1

# ---------------------------------------------------------------
# verify-page-title — real-site check of changing an existing page's title (REST and MCP, title-only and combined, undo note, strict inputs, dry run, approval mode, permissions, core filtering, slug untouched, dynamic post-title render; Docker site; throwaway pages, Project, user, always deleted; approval mode restored)
# ---------------------------------------------------------------
verify-page-title:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-page-title.php --user=1

# ---------------------------------------------------------------
# verify-products — real-site check of list_products / get_product on the dev site's WooCommerce products (throwaway products and a user, always deleted; the existing products must be byte-identical afterwards), then the same tools with WooCommerce deactivated (always reactivated)
# ---------------------------------------------------------------
verify-products:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-products.php --user=1
	@set -e; \
	trap 'docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin activate woocommerce >/dev/null 2>&1' EXIT; \
	docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin deactivate woocommerce >/dev/null 2>&1; \
	docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-products-inactive.php --user=1

# ---------------------------------------------------------------
# verify-oauth — real-site check of the OAuth sign-in: the table store against the reference behaviour, then the whole flow over HTTP (discovery, DCR, consent, token, refresh, rotation, replay, revocation, switch off; dev site only; self-cleaning)
# ---------------------------------------------------------------
verify-oauth:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-oauth-store.php --user=1
	@python3 scripts/verify-oauth-flow.py

# ---------------------------------------------------------------
# verify-test-connection — real-site check of connection diagnosis (Docker site; tests healthy site, wrong key, demoted key owner, plain permalinks, and REST authentication filter)
# ---------------------------------------------------------------
verify-test-connection:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-test-connection.php --user=1

# ---------------------------------------------------------------
# verify-html-to-divi — real-site check of html_to_divi: every fixture saved as a throwaway draft through the real handlers, read back and rendered with the real Divi (Docker site; dev site only; every page it creates is deleted)
# ---------------------------------------------------------------
verify-html-to-divi:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-html-to-divi.php --user=1

# ---------------------------------------------------------------
# verify-components — real-site check of Divi 5.15 Components: the builder-made instances (section, row, module), the Slot (empty and filled) and a nested component validate, save and render with the real Divi (Docker site on Divi 5.15 or newer; dev site only; everything it creates is deleted)
# ---------------------------------------------------------------
verify-components:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-components.php --user=1

# ---------------------------------------------------------------
# verify-style-values — proves in a real browser on the real Divi why a style value with an unclosed quote or bracket is refused (it takes the page's other styles with it) and that balanced values (calc, clamp) pass and apply (dev site only; every page it creates is deleted)
# ---------------------------------------------------------------
verify-style-values:
	@node scripts/verify-style-values.cjs

# ---------------------------------------------------------------
# divi-currency — react to a new Divi release in one command: `make divi-currency ZIP=divi/Divi-X-Y-Z.zip [OLD=divi/old.zip] [INSTALL=1] [GATES=1]`. Writes docs/divi-currency-<version>.md (new and removed modules, changed definitions, new server code, the changelog since the old version); INSTALL=1 upgrades the Docker theme and runs schema-gap + verify-modules; GATES=1 also runs the unit suite and every Divi-dependent real-site gate. It reports; it never promotes a module.
# ---------------------------------------------------------------
divi-currency:
	@bash scripts/divi-currency.sh "$(ZIP)" "$(OLD)" "$(INSTALL)" "$(GATES)"

# ---------------------------------------------------------------
# verify-guide-shapes — proves on the real Divi (and in a real browser) the per-device and equal-size-image shapes the guides teach, and that the converter's rows stack on a phone (Docker site; dev site only; every page it creates is deleted)
# ---------------------------------------------------------------
verify-guide-shapes:
	@node scripts/verify-guide-shapes.cjs

# ---------------------------------------------------------------
# verify-shapes — the effect probe: renders the known-good and known-dead shape candidates on the real Divi in a real browser and fails when the measured effect disagrees with the expectation (Docker site; dev site only; every page it creates is deleted; run ONE probe at a time: each run titles its pages `aied shape probe <runToken> ...` and deletes only its own, a run warns about other probe pages and `node scripts/verify-shapes.cjs --clean-orphans` removes all of them when no probe is running). New candidates: node scripts/verify-shapes.cjs <file>, then php scripts/promote-shapes.php <results> <file>
# ---------------------------------------------------------------
verify-shapes:
	@node scripts/verify-shapes.cjs fixtures/shapes/candidates/known.json

# ---------------------------------------------------------------
# verify-recipes — renders every section recipe (default look and glass-dark, plus every design language on three recipes) on the real Divi at 1280, 900 and 400 px and fails on PHP errors, an empty or overflowing page, columns that do not stack on a phone or a console error; writes dist/contact-sheet/index.html; first proves the stacking measurement on synthetic pages, nested rows included (Docker site; dev site only; ONE run at a time; every page it creates is titled `aied recipe <runToken> ...` and deleted, `node scripts/verify-recipes.cjs --clean-orphans` removes leftovers when no run is going). Narrow it: node scripts/verify-recipes.cjs --names a,b --languages x,y
# ---------------------------------------------------------------
verify-recipes:
	@node scripts/test-recipe-measure.cjs
	@node scripts/verify-recipes.cjs

# ---------------------------------------------------------------
# verify-kits — renders a kit sheet (a primary and a secondary button, a card and a panel on a bg section, a surface section, an accent band and a photo band, an image-first zero-padding panel, and a row of three column cards) for every style kit under editorial and glass-dark, plus every design language under its own kit, on the real Divi at 1280, 900 and 400 px, built through the real pipeline (KitResolver + RecipeTokens, LayoutGate); fails on PHP errors, an empty or overflowing page, a console error, or a button whose computed border, radius, fill, label, text, padding, shadow, hover colours or lift disagree with the resolved markup, or whose label (4.5) or outline (3.0) contrast on the measured surface is too low; and, for the cards, on a missing card (cards), a frame, radius, fill, padding or shadow that disagrees with the resolved markup (card-border, card-radius, card-fill, card-padding, card-shadow), a hover (a real pointer) that disagrees with it or ANY change on a panel (card-hover), an unclipped image (card-clip), heading/paragraph text under 4.5 on the measured card fill or a meaningful frame under 3.0 (card-contrast), a card past its container or window or column cards that do not stack at 400px (overflow), and a run that checked no card; writes dist/kit-sheet/index.html and card-observations.json; first runs the pure tests, the offline run of the sheet through PHP for every kit x language, and the card measurement in a real Chromium on synthetic pages (Docker site; dev site only; ONE run at a time; every page it creates is titled `aied kit <runToken> ...` and deleted, `node scripts/verify-kits.cjs --clean-orphans` removes leftovers when no run is going). Narrow it: node scripts/verify-kits.cjs --kits a,b --languages x,y
# ---------------------------------------------------------------
verify-kits:
	@node scripts/test-verify-kits.cjs
	@node scripts/test-kit-card-run.cjs
	@node scripts/verify-kits.cjs

# ---------------------------------------------------------------
# card-report — classifies every divi/group (and every column with a frame, radius or shadow) of the shipped section recipes as wrapper, padded-wrapper, rule, grid-cell, chip, inverted, card or panel; the card/panel blocks are the ones a card marker goes on (card adapter plan, Task 2). Pure PHP, no site. `make card-report ARGS=--all` lists the plain wrappers too.
# ---------------------------------------------------------------
card-report:
	@php scripts/card-migration-report.php $(ARGS)
