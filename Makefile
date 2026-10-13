.PHONY: up down clean test test-pro build-pro verify-pro-skeleton verify-pro-settings verify-pro-custom-fields verify-pro-create-product verify-pro-update-products verify-pro-theme-layouts verify-pro-theme-templates verify-pro-global-colors verify-pro-global-variables verify-pro-global-presets verify-pro-site-tools verify-pro-rest verify-pro-build-site verify-pro-licence-tiers validate install shell-wp shell-wpcli export-layouts app-password schema-gap verify-modules verify-history verify-dry-run verify-global-styles verify-site-structure verify-acf-custom-fields verify-custom-fields verify-project-layouts verify-products

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
# verify-pro-update-products — real-site check of the Pro tool update_products (self-cleaning; dev site only; free + Pro plugins and WooCommerce active; it records several batches, so on a site with batches it evicts the oldest real ones)
# ---------------------------------------------------------------
verify-pro-update-products:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-pro-update-products.php --user=1

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
# verify-products — real-site check of list_products / get_product on the dev site's WooCommerce products (throwaway products and a user, always deleted; the existing products must be byte-identical afterwards), then the same tools with WooCommerce deactivated (always reactivated)
# ---------------------------------------------------------------
verify-products:
	@docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-products.php --user=1
	@set -e; \
	trap 'docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin activate woocommerce >/dev/null 2>&1' EXIT; \
	docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp plugin deactivate woocommerce >/dev/null 2>&1; \
	docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval-file /scripts/verify-products-inactive.php --user=1
