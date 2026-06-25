.PHONY: up down clean test validate install shell-wp shell-wpcli export-layouts

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
# shell helpers
# ---------------------------------------------------------------
shell-wp:
	docker compose exec wordpress bash

shell-wpcli:
	docker compose exec wpcli bash
