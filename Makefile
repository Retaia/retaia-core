SHELL := /bin/bash

.PHONY: test test-unit test-behat qa ci-local contracts-refresh contracts-check

test:
	composer test

test-unit:
	composer test:unit

test-behat:
	composer test:behat

qa:
	./scripts/no-black-magic.sh
	composer validate --strict --no-check-publish
	php bin/console lint:yaml config
	php bin/console lint:container
	composer check:contracts
	composer check:openapi-docs-coherence
	composer test

ci-local: qa
	composer audit --no-interaction

contracts-refresh:
	composer contracts:refresh

contracts-check:
	composer check:contracts
