# Snowego developer commands. Requires Docker Compose v2.24+.
COMPOSE ?= docker compose
API     := $(COMPOSE) run --rm --no-deps api
NODE    := $(COMPOSE) run --rm --no-deps storefront

.DEFAULT_GOAL := help
.PHONY: help setup up down ps logs migrate seed test test-api test-web lint lint-api lint-web analyse fresh shell-api build-prod

help: ## Show available targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*## "}{printf "  \033[36m%-12s\033[0m %s\n",$$1,$$2}'

.env:
	cp .env.example .env

setup: .env ## First-time setup: env, images, dependencies, app key, migrations
	@grep -q '^APP_KEY=base64:' .env || { sed -i.bak "s|^APP_KEY=.*|APP_KEY=base64:$$(openssl rand -base64 32)|" .env && rm -f .env.bak; }
	$(COMPOSE) build
	$(API) composer install --no-interaction
	$(NODE) npm ci
	$(MAKE) migrate

up: .env ## Start the full stack (http://localhost, http://admin.localhost)
	$(COMPOSE) up -d --wait

down: ## Stop the stack (volumes are kept)
	$(COMPOSE) down

ps: ## Service status and health
	$(COMPOSE) ps

logs: ## Follow logs (S=service)
	$(COMPOSE) logs -f $(S)

migrate: ## Run pending migrations (one-shot)
	$(COMPOSE) run --rm migrate

seed: ## Seed synthetic demo data (dev/staging only)
	$(COMPOSE) run --rm migrate php artisan db:seed --force

fresh: ## Drop and recreate the local database
	$(COMPOSE) run --rm migrate php artisan migrate:fresh --force

test: test-api test-web ## All tests

test-api: ## Pest on PostgreSQL/Redis/MinIO/ClamAV
	$(COMPOSE) up -d --wait clamav
	$(COMPOSE) run --rm -e APP_ENV=testing -e DB_DATABASE=snowego_test api sh -c '\
	  PGPASSWORD=$$DB_PASSWORD psql -h postgres -U $$DB_USERNAME -d postgres -tc "SELECT 1 FROM pg_database WHERE datname = '\''snowego_test'\''" | grep -q 1 \
	  || PGPASSWORD=$$DB_PASSWORD createdb -h postgres -U $$DB_USERNAME snowego_test; \
	  php artisan test'

test-web: ## Frontend typecheck (unit/E2E suites land with features)
	$(NODE) npm run typecheck

lint: lint-api lint-web analyse ## Pint, Larastan, ESLint

lint-api:
	$(API) composer lint

analyse:
	$(API) composer analyse

lint-web:
	$(NODE) npm run lint

shell-api: ## Shell in the API container
	$(COMPOSE) run --rm api sh

build-prod: ## Build production images (API, admin, storefront)
	docker build -f infrastructure/docker/api/Dockerfile --target prod -t snowego/api:local .
	docker build -f infrastructure/docker/admin/Dockerfile --target prod -t snowego/admin:local .
	docker build -f infrastructure/docker/storefront/Dockerfile --target prod -t snowego/storefront:local .
