SHELL := /bin/bash

.PHONY: format format-php format-sh format-prettier

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
