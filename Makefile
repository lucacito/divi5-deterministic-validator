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
# app-password — create a WordPress Application Password for the MCP server
# Prints the credentials to copy into your MCP config / .env
# ---------------------------------------------------------------
app-password:
	@bash scripts/create-app-password.sh

# ---------------------------------------------------------------
# mcp-server — build and print Claude Desktop config snippet
# ---------------------------------------------------------------
mcp-server:
	cd mcp-server && npm install && npm run build
	@echo ""
	@echo "MCP server built. Add this to your Claude Desktop config:"
	@echo "  (claude_desktop_config.json → mcpServers)"
	@echo ""
	@echo '  "ai-editor-divi5": {'
	@echo '    "command": "node",'
	@echo '    "args": ["$(shell pwd)/mcp-server/dist/index.js"],'
	@echo '    "env": {'
	@echo '      "WP_URL": "http://localhost:8181",'
	@echo '      "WP_USER": "admin",'
	@echo '      "WP_APP_PASSWORD": "<from make app-password>"'
	@echo '    }'
	@echo '  }'

# ---------------------------------------------------------------
# shell helpers
# ---------------------------------------------------------------
shell-wp:
	docker compose exec wordpress bash

shell-wpcli:
	docker compose exec wpcli bash
