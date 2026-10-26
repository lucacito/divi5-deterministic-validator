.PHONY: up down clean test test-pro build-pro verify-pro-skeleton verify-pro-settings verify-pro-custom-fields verify-pro-create-product verify-pro-update-products verify-pro-bulk-adjust-prices verify-pro-switch-design verify-pro-theme-layouts verify-pro-theme-templates verify-pro-global-colors verify-pro-global-variables verify-pro-global-presets verify-pro-site-tools verify-pro-rest verify-pro-build-site verify-pro-licence-tiers validate install shell-wp shell-wpcli export-layouts app-password schema-gap verify-modules verify-history verify-dry-run verify-global-styles verify-site-structure verify-acf-custom-fields verify-custom-fields verify-project-layouts verify-page-title verify-products verify-oauth verify-test-connection verify-html-to-divi verify-guide-shapes verify-shapes verify-recipes verify-kits verify-language-type verify-components verify-style-values card-report type-report rhythm-report dogfood-prepare dogfood-score dogfood-render divi-currency

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
# verify-pro-switch-design — real-site check of the Pro tool switch_design: six clean recipes built under editorial + quiet-solid are switched to luxe + gilt-hairline through the REST route and the MCP handler (dry run, one-time token, a page edited since the preview skipped, permissions, undo byte for byte) and the saved page must EQUAL the same recipes built directly under the target design, byte for byte; then both pages are rendered by the real Divi at 1280 and 400 px and every module's computed styles and box are compared (the run fails when nothing was compared); first runs the pure tests; screenshots in dist/switch-design/ (Docker site; dev site only; free + Pro plugins active, no licence needed; ONE gate at a time; every page is titled `aied switch <runToken> <role>` and deleted, `node scripts/verify-pro-switch-design.cjs --clean-orphans` removes leftovers when no run is going; it records two batches and deletes them, so a site with 20 batches already loses its oldest)
# ---------------------------------------------------------------
verify-pro-switch-design:
	@node scripts/test-verify-pro-switch-design.cjs
	@node scripts/verify-pro-switch-design.cjs $(ARGS)

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
# verify-html-to-divi — real-site check of html_to_divi: every fixture saved as a throwaway draft through the real handlers, read back and rendered with the real Divi; then the pure tests of the styles gate; then the styles gate itself (styles=basic: the six 14-styled fixtures compared, in a real browser at 1280/900/400, with their source and with what the converter wrote; screenshots in dist/html-to-divi/) (Docker site; dev site only; every page it creates is deleted; run ONE gate at a time)
# ---------------------------------------------------------------
verify-html-to-divi:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-html-to-divi.php --user=1
	@node scripts/test-verify-html-styles.cjs
	@node scripts/verify-html-to-divi-styles.cjs

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
# verify-kits — renders a kit sheet (a primary and a secondary button, a card and a panel on a bg section, a surface section, an accent band and a photo band, an image-first zero-padding panel, an inverted card, an inverted panel and an accent tile on the two light bands, an inverted panel holding a primary and a secondary kit button, and a row of three column cards whose middle one is an accent ground) for every style kit under editorial and glass-dark, plus every design language under its own kit, on the real Divi at 1280, 900 and 400 px, built through the real pipeline (KitResolver + RecipeTokens, LayoutGate); fails on PHP errors, an empty or overflowing page, a console error, or a button whose computed border, radius, fill, label, text, padding, shadow, hover colours or lift disagree with the resolved markup, or whose label (4.5) or outline (3.0) contrast on the measured surface is too low; and, for the cards, on a missing card (cards), a frame, radius, fill, padding or shadow that disagrees with the resolved markup (card-border, card-radius, card-fill, card-padding, card-shadow), a hover (a real pointer) that disagrees with it or ANY change on a panel (card-hover), an unclipped image (card-clip), heading/paragraph text under 4.5 on the measured card fill or a meaningful frame under 3.0 (card-contrast), a card past its container or window or column cards that do not stack at 400px (overflow), a ground whose painted fill is not the resolved one (ground-fill), a kit button on the inverted panel with an unreadable label or a weak outline or the wrong hover colours (panel-button), an accent middle card that is not at least 1.5x as prominent as its neighbours (featured), and a run that checked no card; and, for the 10 item blocks (an `item` accordion on every band, a boxed `item` toggle and two `item-rule` toggles on the two light bands), on a block that is missing or has the wrong items or opening state (items), a frame, radius, padding, shadow, state fill, title colour, answer colour or icon colour that disagrees with the resolved markup in the item's own state, open or closed (item-border, item-radius, item-padding, item-shadow, item-fill, item-title, item-body, item-icon), a title or answer under 4.5, an icon or meaningful frame under 3.0 on the measured fill (item-contrast), a REAL CLICK on an item's title that does not open it, does not close the previously open accordion item, leaves an answer hidden or shown against its item (item-click), and a run that checked no item; and, for the 2 forms (a `form` contact form with two fields, the basic captcha and a submit on the bg band and on the surface band), on a form that is missing or has the wrong fields, no captcha or not one submit, and a run that checked no form (form), a field fill (computed and painted), frame (each side, so an underline draws one), radius, ink, `::placeholder` colour or padding that disagrees with the resolved markup (form-field-fill, form-field-border, form-field-radius, form-field-ink, form-field-placeholder, form-field-padding), a submit button that disagrees with the kit's primary control at rest or on a REAL hover (form-button; an underline submit keeps its bottom edge colour on hover), a captcha not in the band's ink (form-captcha), anything of the kit on the form's own container (form-container), ink or placeholder under 4.5 on the painted field, a frame under 3.0 on the field fill, a field boundary (the better of its frame and its fill) under 3.0 on the form behind it, or a submit or captcha unreadable on the form behind it (form-contrast; `form-expectation` if the expectation gate is bypassed), a field or the submit cut by the container (form-clip); and, for the 1 image (a `divi/image` on the bg band whose radius is the whole-value token `{{aied:kit:radius-media}}`, a pack picture, found by its unique alt text), on an image that is missing or on the page twice, and a run that checked no image (media), a corner of the img, its wrapper or the module box that disagrees with the four corners of the resolved markup (a kit with media 0px is checked as a square image: a stray radius fails) or no element carrying the radius (media-radius), a wrapper that carries the radius without clipping the picture, a rounded img cut square by a smaller clipping wrapper or an img sticking out of a non-clipping wrapper (media-clip); and, for the 16 rules (a `rule` and a `rule-strong` divider and top-rule group on the bg band and on the photo band, a `rule` column rule, a `rule-strong` divider in an inverted panel with `on:"panel"`, a divider of each role on the surface band and on the accent band, and a top-rule group of each role in the inverted panel) and the 5 chips (a tag group and an icon chip on the bg band, a tag on the surface band and on the photo band, an icon chip on the accent band), each found by a unique heading or text, a rule or chip that is missing or on the page twice and a run that checked no rule or no chip (rule, chip), a line whose style, width or colour disagrees with the resolved markup, a divider on its `::before` and a group or column on its own `border-top` with the other three sides at 0px (rule-style, rule-width, rule-colour), a line under its contrast against the PAINTED band (a strong rule 3.0, a quiet one 1.1; rule-contrast), a chip corner that disagrees with the kit's control radius (a 0px kit is four zeros; chip-radius), a tag frame side that disagrees (chip-frame), a tag with a fill, an icon fill or glyph colour that disagrees with the resolved markup or a fill painted over (chip-fill), and a glyph under 3.0 on its painted fill, an icon fill the colour of the band or a tag frame too faint for the band (chip-contrast); and, for the 8 carriers of the carriers adapter (a testimonial `card` on the bg band and `panel` on the surface band, a blurb `chip` on the surface and accent bands, a number-counter `rule-strong` on the accent band and `rule` on the bg band, a `rule-accent` divider on the bg and surface bands; each found by a unique author, title or heading text), a carrier that is missing or on the page twice and a run that checked no carrier (carrier), a testimonial fill (computed and painted), frame, radius, padding or shadow that disagrees with the resolved markup (testimonial-fill, testimonial-frame, testimonial-radius, testimonial-padding, testimonial-shadow), a hover (a real pointer) that disagrees with it or any change on a panel (testimonial-hover), a quote disc (the `::before`) that is not rendered or a module whose overflow is not visible so the hanging disc would be cut (testimonial-quote), author or quote text under 4.5 or a meaningful frame under 3.0 on the painted fill (testimonial-contrast), a blurb icon corner, fill or glyph colour that disagrees with the resolved markup (blurb-chip-radius, blurb-chip-fill, blurb-chip-glyph), a glyph under 3.0 on its painted fill or a fill the colour of its band (blurb-chip-contrast), a counter's top line style, width, colour or any other side that disagrees (counter-rule-style, counter-rule-width, counter-rule-colour) or is under its contrast against the painted band (counter-rule-contrast), and the accent mark's line on the divider's `::before` that disagrees (accent-rule-style, accent-rule-width, accent-rule-colour) or is under the accent floor the PHP exports as `accentRuleFloor` (accent-rule-contrast); writes dist/kit-sheet/index.html, card-observations.json, item-observations.json, form-observations.json, media-observations.json, rule-observations.json, chip-observations.json and carrier-observations.json; first runs the pure tests, the offline run of the sheet through PHP for every kit x language, and the card, item (with a real click), form, image, rule, chip and carrier measurement in a real Chromium on synthetic pages (Docker site; dev site only; ONE run at a time; every page it creates is titled `aied kit <runToken> ...` and deleted, `node scripts/verify-kits.cjs --clean-orphans` removes leftovers when no run is going). Narrow it: node scripts/verify-kits.cjs --kits a,b --languages x,y
# ---------------------------------------------------------------
verify-kits:
	@node scripts/test-verify-kits.cjs
	@node scripts/test-kit-card-run.cjs
	@node scripts/verify-kits.cjs

# ---------------------------------------------------------------
# verify-language-type — renders a type sheet (a display h1 and h2, a title h3 in a padded group, an eyebrow h4 and an eyebrow text, each with a type marker and the language's font tokens) followed by four rhythm sections (section, open, tight, and open over a photo, each with a rhythm marker and no padding of its own) for every design language on the real Divi at 1280, 900 and 400 px, built through the real pipeline (SectionRecipes::resolveMarkup with the language and its own kit, LayoutGate); the expected weight, letter spacing, capitals and family are read from the RESOLVED markup, never typed; fails on PHP errors, an empty or overflowing page, a console error, a slot that is missing or on the page twice or a run that checked no slot (type), an expectation with a hole (type-expectation), a computed font-weight, letter-spacing, text-transform or first font-family that disagrees (type-weight, type-tracking, type-caps, type-family), a web font with no loaded face at the weight after document.fonts.ready (type-font-loaded) and a heading or text wider than its column or the window (type-overflow, overflow); for the rhythm sections a slot that is missing or on the page twice or a run that checked no rhythm slot (rhythm), an expectation with a hole (rhythm-expectation), a computed padding-top or padding-bottom that disagrees with the resolved markup at 1280, 900 or 400 px (rhythm-padding), a MEASURED order that is not open > section > tight (rhythm-order) and a photo section that lost its picture (rhythm-image); writes dist/type-sheet/ (screenshots, observations.json, rhythm-observations.json, index.html); first runs the pure tests and the page measurements against synthetic pages in a real Chromium, one departure per code (Docker site; dev site only; ONE run at a time, from the main checkout with the branch checked out; every page it creates is titled `aied type <runToken> <language>` and deleted, `node scripts/verify-language-type.cjs --clean-orphans` removes leftovers when no run is going). Narrow it: ARGS="--languages brutalist-flat,luxe"
# ---------------------------------------------------------------
verify-language-type:
	@node scripts/test-verify-language-type.cjs
	@node scripts/test-language-type-run.cjs
	@node scripts/test-verify-language-rhythm.cjs
	@node scripts/test-language-rhythm-run.cjs
	@node scripts/verify-language-type.cjs $(ARGS)

# ---------------------------------------------------------------
# card-report — classifies every divi/group (and every column with a frame, radius or shadow) of the shipped section recipes as wrapper, padded-wrapper, rule, grid-cell, chip, inverted, card or panel; the card/panel blocks are the ones a card marker goes on (card adapter plan, Task 2). Pure PHP, no site. `make card-report ARGS=--all` lists the plain wrappers too.
# ---------------------------------------------------------------
card-report:
	@php scripts/card-migration-report.php $(ARGS)

# ---------------------------------------------------------------
# type-report — classifies every divi/heading and every uppercase eyebrow-font divi/text of the shipped section recipes as display, title or eyebrow (the blocks a type marker goes on) or as a named exemption, and prints the totals (type adapter plan, Task 4). A block that already carries a type marker is classified by it and shown MARKED. Pure PHP, no site. `make type-report ARGS=--all` lists the texts that are no label too.
# ---------------------------------------------------------------
type-report:
	@php scripts/type-migration-report.php $(ARGS)

# ---------------------------------------------------------------
# rhythm-report — classifies every divi/section of the shipped section recipes as section, open or tight (the sections a rhythm marker goes on) or as a named exemption, and prints the totals (rhythm adapter plan, Task 4). A section that already carries a rhythm marker is classified by it and shown MARKED. Pure PHP, no site, no arguments.
# ---------------------------------------------------------------
rhythm-report:
	@php scripts/rhythm-migration-report.php $(ARGS)

# shq: one shell word in single quotes, with an embedded single quote escaped ('\''), so values with spaces or quotes stay ONE argument.
# An empty value becomes an empty word (the script then reports the missing argument); $(ARGS) stays unquoted on purpose (several flags).
shq = '$(subst ','\'',$(1))'

# ---------------------------------------------------------------
# dogfood-prepare — dev-only dogfood routine (docs/dogfood.md), step 1: writes the served guide (the default guide plus the topics kit-rules, style-kit:<kit>, design-language:<language>, controls, forms and kit-patterns) and a TASK.md into dist/dogfood/<kit>-<language> (TASK=edit: also a base page.html of six recipes under the kit, into dist/dogfood/<kit>-<language>-edit; OUT=<dir> to choose) for a FRESH assistant to build or edit a page from. Pure PHP, no site, no network, no model call; re-running into the same out dir refreshes the files of the earlier run (guide files, TASK.md, a base page.html) while your page and any foreign file are never touched, and refuses (nothing written) to overwrite anything else that differs. Not part of `make test`. make dogfood-prepare KIT=double-frame LANGUAGE=corporate-trust
# ---------------------------------------------------------------
dogfood-prepare:
	@php scripts/dogfood-prepare.php $(call shq,$(KIT)) $(call shq,$(LANGUAGE)) $(if $(TASK),--task $(call shq,$(TASK))) $(if $(OUT),--out $(call shq,$(OUT)))

# ---------------------------------------------------------------
# dogfood-score — dev-only dogfood routine, step 3: statically scores a page an assistant wrote against what the real kit adapters write for that kit and the language adapters for that language (9 categories, 39 checks, type and rhythm included; colours by role-set membership in the language; blocks it cannot place are listed as UNCLASSIFIED, never counted). Pure PHP, no site. PAGE=<file> KIT=<id> [LANGUAGE=<id>] [ARGS="--json --min 90"]; exit 0 after measuring, 1 only below --min, 2 on a usage error or a page that does not parse. Not part of `make test`.
# ---------------------------------------------------------------
dogfood-score:
	@php scripts/dogfood-score.php $(call shq,$(PAGE)) $(call shq,$(KIT)) $(if $(LANGUAGE),--language $(call shq,$(LANGUAGE))) $(ARGS)

# ---------------------------------------------------------------
# dogfood-render — dev-only dogfood routine, step 4: runs the pure tests of the script, then validates ONE page through the real LayoutGate on the dev site, saves it as ONE throwaway draft exactly as given, renders it at 1280 and 400 px (overflow, console errors, PHP error text, screenshots into dist/dogfood/<name>/) and deletes it. Docker site; dev site only; ONE run at a time; the draft is titled `aied dogfood <runToken> <name>` and deleted by that exact prefix; ARGS=--clean-orphans (with no PAGE) removes leftovers when no run is going. There is no --kit option. make dogfood-render PAGE=dist/dogfood/<dir>/page.html
# ---------------------------------------------------------------
dogfood-render:
	@node scripts/test-dogfood-render.cjs
	@node scripts/dogfood-render.cjs $(if $(PAGE),$(call shq,$(PAGE))) $(ARGS)
