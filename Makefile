SHELL := /bin/bash

.PHONY: test test-unit test-behat qa ci-local

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
	composer test

ci-local: qa
	composer audit --no-interaction
