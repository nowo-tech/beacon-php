# Makefile for beacon-php (nowo-tech/beacon-php)
# REQ-MAKE-001 / REQ-MAKE-002 / REQ-MAKE-006

.PHONY: help ensure-up up down build shell install test test-coverage test-matrix parity cs-check cs-fix rector rector-dry \
	phpstan qa release-check composer-sync clean update validate setup-hooks check-no-cursor-coauthor

COMPOSER_BIN := /usr/bin/composer
COMPOSE := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
# Directory of a BeaconBundle checkout for the live parity tests (make parity BUNDLE=../BeaconBundle).
BUNDLE ?=

help:
	@echo "beacon-php — Development Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "  up             Build and start the Docker container (PHP_VERSION=8.4 by default)"
	@echo "  down           Stop the container (and remove orphans)"
	@echo "  shell          Open a shell in the container"
	@echo "  install        composer install"
	@echo "  test           PHPUnit"
	@echo "  test-coverage  PHPUnit with PCOV coverage (fails below COVERAGE_MIN, default 99)"
	@echo "  test-matrix    PHPUnit on PHP 8.2, 8.3, 8.4 and 8.5 (throwaway --rm containers)"
	@echo "  parity         Byte-for-byte parity against a BeaconBundle checkout (BUNDLE=path)"
	@echo "  cs-check       Check code style"
	@echo "  cs-fix         Fix code style"
	@echo "  rector-dry     Rector dry run"
	@echo "  phpstan        PHPStan (level max)"
	@echo "  qa             cs-check + phpstan + test"
	@echo "  release-check  Full pre-release chain"
	@echo "  setup-hooks    Install git hooks (REQ-GIT-001)"

ensure-up:
	@if ! $(COMPOSE) exec -T php true 2>/dev/null; then \
		echo "Starting container..."; \
		$(COMPOSE) up -d --build; \
		$(COMPOSE) exec -T php $(COMPOSER_BIN) install --no-interaction; \
	fi

build:
	$(COMPOSE) build

up:
	$(COMPOSE) up -d --build
	$(COMPOSE) exec -T php $(COMPOSER_BIN) install --no-interaction

down:
	$(COMPOSE) down --remove-orphans

shell: ensure-up
	$(COMPOSE) exec php sh

install: ensure-up
	$(COMPOSE) exec -T php $(COMPOSER_BIN) install --no-interaction

test: ensure-up
	$(COMPOSE) exec -T php $(COMPOSER_BIN) test

test-coverage: ensure-up
	$(COMPOSE) exec -T php $(COMPOSER_BIN) test-coverage | tee coverage-php.txt
	sh ./.scripts/php-coverage-percent.sh coverage-php.txt

# One throwaway container per PHP version (vendor/ is resolved for platform PHP 8.2, so it runs on every version).
test-matrix: ensure-up
	@for v in 8.2 8.3 8.4 8.5; do \
		echo "== PHP $$v"; \
		docker run --rm -v "$(CURDIR):/app" -w /app php:$$v-cli-alpine php vendor/bin/phpunit --colors=always || exit 1; \
	done

parity: ensure-up
	@test -n "$(BUNDLE)" || (echo "Usage: make parity BUNDLE=/path/to/BeaconBundle" && exit 1)
	docker run --rm -v "$(CURDIR):/app" -v "$(abspath $(BUNDLE)):/bundle:ro" -e BEACON_BUNDLE_SRC=/bundle/src -w /app \
		php:8.4-cli-alpine php vendor/bin/phpunit --colors=always --testsuite parity

cs-check: ensure-up
	$(COMPOSE) exec -T php $(COMPOSER_BIN) cs-check

cs-fix: ensure-up
	$(COMPOSE) exec -T php $(COMPOSER_BIN) cs-fix

rector: ensure-up
	$(COMPOSE) exec -T php $(COMPOSER_BIN) rector

rector-dry: ensure-up
	$(COMPOSE) exec -T php $(COMPOSER_BIN) rector-dry

phpstan: ensure-up
	$(COMPOSE) exec -T php $(COMPOSER_BIN) phpstan

qa: ensure-up
	$(COMPOSE) exec -T php $(COMPOSER_BIN) qa

composer-sync: ensure-up
	$(COMPOSE) exec -T php $(COMPOSER_BIN) validate --strict
	$(COMPOSE) exec -T php $(COMPOSER_BIN) update --lock --no-interaction --no-install

update: ensure-up
	$(COMPOSE) exec -T php $(COMPOSER_BIN) update --no-interaction

validate: ensure-up
	$(COMPOSE) exec -T php $(COMPOSER_BIN) validate --strict

release-check:
	@$(MAKE) ensure-up
	@$(MAKE) check-no-cursor-coauthor
	@$(MAKE) validate
	@$(MAKE) cs-check
	@$(MAKE) rector-dry
	@$(MAKE) phpstan
	@$(MAKE) test-coverage
	@$(MAKE) test-matrix

clean:
	rm -rf vendor .phpunit.cache coverage coverage.xml coverage-php.txt .php-cs-fixer.cache

check-no-cursor-coauthor:
	@chmod +x .scripts/check-no-cursor-coauthor.sh .scripts/check-no-claude-coauthor.sh
	@./.scripts/check-no-cursor-coauthor.sh HEAD

setup-hooks:
	@chmod +x .githooks/commit-msg 2>/dev/null || true
	@git config core.hooksPath .githooks
	@echo "Git hooks installed (.githooks — commit-msg strips agent co-author trailers, REQ-GIT-001)."
