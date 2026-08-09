DOCKER := docker run --rm -v "$(PWD)":/app -w /app composer:2
DOCKER_HOST := docker run --rm --network host -v "$(PWD)":/app -w /app
PCOV_BOOTSTRAP := apk add --no-cache $$PHPIZE_DEPS >/dev/null && pecl install pcov >/dev/null && docker-php-ext-enable pcov

.PHONY: build cs cs-fix psalm test mutation rector rector-fix install normalize require-checker \
       test-coverage test-coverage-ci update-deps release-check bc-check audit-package \
       docs-api docs-build docs-dev docs-cookbook docs-links docs-vale

# Prose lint runs over the hand-written sections only: docs/src/api/** is
# generated from docblocks, and a style linter on it reports nothing that can
# be fixed on the site (plan §I.3).
VALE_PATHS := docs/src/index.md docs/src/guide docs/src/cookbook docs/src/adapters

install:
	$(DOCKER) composer install --no-interaction --no-progress --prefer-dist

build:
	$(DOCKER) composer build

cs:
	$(DOCKER) composer cs

cs-fix:
	$(DOCKER) composer cs:fix

psalm:
	$(DOCKER) composer psalm

test:
	$(DOCKER) composer test

test-coverage:
	$(DOCKER) sh -lc '$(PCOV_BOOTSTRAP) && composer test:coverage'

test-coverage-ci:
	$(DOCKER) sh -lc '$(PCOV_BOOTSTRAP) && composer test:coverage:ci'

mutation:
	$(DOCKER) sh -lc '$(PCOV_BOOTSTRAP) && composer mutation'

rector:
	$(DOCKER) composer rector

rector-fix:
	$(DOCKER) composer rector:fix

normalize:
	$(DOCKER) sh -c 'git config --global --add safe.directory /app; composer normalize'

require-checker:
	$(DOCKER) composer require-checker

update-deps:
	$(DOCKER) sh -c 'git config --global --add safe.directory /app; composer update -q; composer normalize'

release-check:
	$(DOCKER) composer release-check
	$(MAKE) mutation

bc-check:
	$(DOCKER) sh -c 'git config --global --add safe.directory "*"; \
	  LATEST=$$(git describe --tags --abbrev=0 2>/dev/null || true); \
	  if [ -n "$$LATEST" ]; then \
	    composer bc-check -- --from=$$LATEST; \
	  else \
	    echo "No previous tag - skipping BC check"; \
	  fi'

help:
	@echo "Usage: make <target>"
	@echo ""
	@echo "Targets:"
	@echo "  install          composer install"
	@echo "  build            full gate (validate + normalize + cs + psalm + test)"
	@echo "  cs               check code style (dry-run)"
	@echo "  cs-fix           fix code style"
	@echo "  psalm            static analysis"
	@echo "  test             run testo (Unit suite)"
	@echo "  test-coverage    run testo with coverage"
	@echo "  test-coverage-ci run testo coverage for CI artifacts"
	@echo "  mutation         mutation testing"
	@echo "  rector           check rector (dry-run)"
	@echo "  rector-fix       apply rector fixes"
	@echo "  normalize        normalize composer.json"
	@echo "  require-checker  check composer dependencies"
	@echo "  update-deps      composer update + normalize"
	@echo "  bc-check         check backward compatibility against latest tag"
	@echo "  release-check    build + rector + bc-check + mutation"
	@echo ""
	@echo "Documentation site (docs/):"
	@echo "  docs-api         install the API workspace, re-reflect, regenerate api/ pages"
	@echo "  docs-build       generate + integrity check + vitepress build + anchor check"
	@echo "  docs-dev         local dev server"
	@echo "  docs-cookbook    run examples/case-studies/ and diff against the cookbook pages"
	@echo "  docs-links       check external links (network)"
	@echo "  docs-vale        prose lint the hand-written pages (needs vale on PATH)"

# --- documentation site (docs/, plan §I) -------------------------------------
# Node runs on the host; PHP does not. Only docs-api and docs-cookbook need
# PHP, and both reach it through the same composer:2 image as everything else.

docs-api:
	$(DOCKER) sh -c 'cd docs/.api-workspace && composer install --no-interaction --no-progress -q'
	$(DOCKER) php docs/scripts/reflect-api.php > docs/scripts/api-snapshot.json
	cd docs && npm run docs:api

docs-build:
	cd docs && npm run docs:build

docs-dev:
	cd docs && npm run docs:dev

docs-cookbook:
	PHP_BIN="docker run --rm -v $(PWD):/app -w /app composer:2 php" node docs/scripts/check-cookbook.mjs

docs-links:
	node docs/scripts/check-links.mjs

docs-vale:
	vale sync
	vale $(VALE_PATHS)

audit-package:
	@if [ -f ../bin/package-audit ]; then bash ../bin/package-audit "$(CURDIR)"; else echo "package-audit: available only inside the monorepo"; fi
