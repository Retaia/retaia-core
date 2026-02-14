SHELL := /bin/bash

.PHONY: test test-unit test-behat qa ci-local staging-up staging-down staging-logs staging-migrate staging-health

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

staging-up:
	docker compose -f docker-compose.staging.yml up -d app-staging caddy-staging database-staging

staging-down:
	docker compose -f docker-compose.staging.yml down

staging-logs:
	docker compose -f docker-compose.staging.yml logs -f --tail=200 app-staging caddy-staging database-staging

staging-migrate:
	docker compose -f docker-compose.staging.yml exec app-staging php bin/console doctrine:migrations:migrate --no-interaction

staging-health:
	curl -sS -H "Host: api-staging.retaia.test" "http://localhost:$${RETAIA_STAGING_HTTP_PORT:-18081}/api/v1/health"
