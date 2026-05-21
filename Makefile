SHELL := /bin/bash

.PHONY: help install hooks check test analyse format format-php format-sh format-prettier dist clean

help:
	@echo "make install         Install composer + npm deps"
	@echo "make hooks           Enable versioned git hooks under .githooks/ (pre-commit lint)"
	@echo "make check           Lint + phpstan + phpunit (CI gate)"
	@echo "make test            Run PHPUnit"
	@echo "make analyse         Run PHPStan"
	@echo "make format          Format PHP + shell + prettier"
	@echo "make dist            Build release tarball under dist/"

hooks:
	git config core.hooksPath .githooks
	@echo "Git hooks enabled. Pre-commit will run php-cs-fixer + prettier."
	@echo "Skip on a single commit with: git commit --no-verify"

install:
	composer install
	if command -v npm >/dev/null 2>&1; then npm install; fi

check:
	composer run check

test:
	composer run test

analyse:
	composer run analyse

format: format-php format-sh format-prettier

format-php:
	composer run format:php

format-sh:
	bash scripts/format-sh.sh --write

format-prettier:
	if command -v npm >/dev/null 2>&1 && [ -f package.json ]; then \
		npm run --silent format:prettier; \
	else \
		echo "Skipping Prettier: npm or package.json not found"; \
	fi

dist:
	rm -rf dist && mkdir -p dist/zonemirror
	rsync -a --exclude='.git' --exclude='.github' --exclude='tests' \
		--exclude='node_modules' --exclude='dist' --exclude='*.log' \
		./ dist/zonemirror/
	composer install --working-dir=dist/zonemirror --no-dev --prefer-dist --optimize-autoloader
	tar -czf dist/zonemirror.tar.gz -C dist zonemirror
	(cd dist && sha256sum zonemirror.tar.gz > zonemirror.tar.gz.sha256)
	@echo "Built dist/zonemirror.tar.gz"

clean:
	rm -rf vendor node_modules dist .phpunit.cache coverage* .php-cs-fixer.cache
