SHELL := /bin/bash

.PHONY: help install check test analyse format format-php format-sh format-prettier dist clean

help:
	@echo "make install         Install composer + npm deps"
	@echo "make check           Lint + phpstan + phpunit (CI gate)"
	@echo "make test            Run PHPUnit"
	@echo "make analyse         Run PHPStan"
	@echo "make format          Format PHP + shell + prettier"
	@echo "make dist            Build release tarball under dist/"

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
	rm -rf dist && mkdir -p dist/cloudflare-dns-sync
	rsync -a --exclude='.git' --exclude='.github' --exclude='tests' \
		--exclude='node_modules' --exclude='dist' --exclude='*.log' \
		./ dist/cloudflare-dns-sync/
	composer install --working-dir=dist/cloudflare-dns-sync --no-dev --prefer-dist --optimize-autoloader
	tar -czf dist/cloudflare-dns-sync.tar.gz -C dist cloudflare-dns-sync
	(cd dist && sha256sum cloudflare-dns-sync.tar.gz > cloudflare-dns-sync.tar.gz.sha256)
	@echo "Built dist/cloudflare-dns-sync.tar.gz"

clean:
	rm -rf vendor node_modules dist .phpunit.cache coverage* .php-cs-fixer.cache
