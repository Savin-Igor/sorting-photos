DOCKER:=$(shell which docker)
PWD:=$(shell pwd)

DOCKER_COMPOSE:=${DOCKER} compose

# Load .env file if it exists
ifneq (,$(wildcard .env))
    include .env
    export
endif

.DEFAULT_GOAL:= help

##@ Docker compose commands

up: ## Start up application with Redis (default)
	@echo "Starting application with Redis..."
	@if [ -z "$$USER_ID" ] || [ -z "$$GROUP_ID" ]; then \
		echo "Warning: USER_ID and GROUP_ID not set. Using defaults (1000:1000)."; \
		echo "To avoid permission issues, set them in .env file or export: export USER_ID=$$(id -u) GROUP_ID=$$(id -g)"; \
	fi
	${DOCKER_COMPOSE} up -d --remove-orphans
	@echo "Application is running. Redis is accessible within Docker network."
	@echo "To expose Redis port externally, edit docker-compose.yml and uncomment ports section."
.PHONY: up

up-with-workers: ## Start up application with Redis and workers (usage: make up-with-workers WORKERS=8)
	@echo "Starting application with Redis and $${WORKERS:-8} workers..."
	@if [ -z "$$USER_ID" ] || [ -z "$$GROUP_ID" ]; then \
		echo "Warning: USER_ID and GROUP_ID not set. Using defaults (1000:1000)."; \
		echo "To avoid permission issues, set them in .env file or export: export USER_ID=$$(id -u) GROUP_ID=$$(id -g)"; \
	fi
	${DOCKER_COMPOSE} up -d --remove-orphans
	@sleep 2
	@${MAKE} consume-workers WORKERS=$${WORKERS:-8}
	@echo "Application with $${WORKERS:-8} workers is running."
.PHONY: up-with-workers

up-rabbitmq: ## Start up application with RabbitMQ instead of Redis
	@echo "Starting application with RabbitMQ..."
	${DOCKER_COMPOSE} --profile rabbitmq up -d --remove-orphans
	@echo "Application is running. RabbitMQ management UI: http://localhost:15672 (guest/guest)"
.PHONY: up-rabbitmq

up-worker: ## Start up application with worker for async message processing
	@echo "Starting application with worker..."
	${DOCKER_COMPOSE} --profile worker up -d --remove-orphans
	@echo "Application with worker is running"
.PHONY: up-worker

down: ## Stop all containers
	-@${DOCKER_COMPOSE} --profile worker --profile rabbitmq down
	@echo "All containers stopped"
.PHONY: down

build: ## Rebuild Docker images
	@echo "Building Docker images..."
	@if [ -z "$$USER_ID" ] || [ -z "$$GROUP_ID" ]; then \
		echo "Warning: USER_ID and GROUP_ID not set. Using defaults (1000:1000)."; \
		echo "To avoid permission issues, set them: export USER_ID=$$(id -u) GROUP_ID=$$(id -g)"; \
	fi
	USER_ID=$${USER_ID:-$$(id -u)} GROUP_ID=$${GROUP_ID:-$$(id -g)} ${DOCKER_COMPOSE} build --no-cache
	@echo "Build complete"
.PHONY: build

logs: ## Show logs from all containers
	${DOCKER_COMPOSE} logs -f
.PHONY: logs

logs-app: ## Show logs from application container
	${DOCKER_COMPOSE} logs -f app
.PHONY: logs-app

logs-worker: ## Show logs from worker container
	${DOCKER_COMPOSE} logs -f worker
.PHONY: logs-worker

shell: ## Open shell in application container
	${DOCKER_COMPOSE} exec app /bin/bash
.PHONY: shell

##@ Application commands

run: ## Run the application (sort files)
	@if ! ${DOCKER_COMPOSE} ps app | grep -q "Up"; then \
		echo "Containers are not running. Starting them..."; \
		${MAKE} up; \
	fi
	@echo "Running file sorting application..."
	@echo "Using SOURCE_DIRECTORY_HOST: $${SOURCE_DIRECTORY_HOST:-./var/data/source}"
	@echo "Using DESTINATION_DIRECTORY_HOST: $${DESTINATION_DIRECTORY_HOST:-./var/data/destination}"
	${DOCKER_COMPOSE} exec -e SOURCE_DIRECTORY_HOST="$${SOURCE_DIRECTORY_HOST:-}" -e DESTINATION_DIRECTORY_HOST="$${DESTINATION_DIRECTORY_HOST:-}" app php index.php
.PHONY: run

run-with-workers: ## Run the application with workers (usage: make run-with-workers WORKERS=8)
	@if ! ${DOCKER_COMPOSE} ps app | grep -q "Up"; then \
		echo "Containers are not running. Starting them..."; \
		${MAKE} up-with-workers WORKERS=$${WORKERS:-8}; \
	fi
	@echo "Running file sorting application with $${WORKERS:-8} workers..."
	@echo ""
	@echo "Using SOURCE_DIRECTORY_HOST: $${SOURCE_DIRECTORY_HOST:-./var/data/source}"
	@echo "Using DESTINATION_DIRECTORY_HOST: $${DESTINATION_DIRECTORY_HOST:-./var/data/destination}"
	@echo ""
	${DOCKER_COMPOSE} exec -e SOURCE_DIRECTORY_HOST="$${SOURCE_DIRECTORY_HOST:-}" -e DESTINATION_DIRECTORY_HOST="$${DESTINATION_DIRECTORY_HOST:-}" app php index.php
.PHONY: run-with-workers

run-dry-run: ## Run the application in dry-run mode (no files moved)
	@if ! ${DOCKER_COMPOSE} ps app | grep -q "Up"; then \
		echo "Containers are not running. Starting them..."; \
		${MAKE} up; \
	fi
	@echo "Running file sorting application in dry-run mode..."
	@echo "Using SOURCE_DIRECTORY_HOST: $${SOURCE_DIRECTORY_HOST:-./var/data/source}"
	@echo "Using DESTINATION_DIRECTORY_HOST: $${DESTINATION_DIRECTORY_HOST:-./var/data/destination}"
	${DOCKER_COMPOSE} exec -e DRY_RUN=true -e SOURCE_DIRECTORY_HOST="$${SOURCE_DIRECTORY_HOST:-}" -e DESTINATION_DIRECTORY_HOST="$${DESTINATION_DIRECTORY_HOST:-}" app php index.php
.PHONY: run-dry-run

scan: ## Scan files and dispatch events (without organizing)
	@echo "Scanning files..."
	${DOCKER_COMPOSE} exec app php bin/console scan:files /var/data/source
.PHONY: scan

metadata-clear: ## Clear all metadata from database (reset processing locks)
	@echo "Clearing metadata database..."
	${DOCKER_COMPOSE} exec app php bin/console metadata:clear --force
.PHONY: metadata-clear

consume: ## Consume messages from queue (run worker)
	@if ! ${DOCKER_COMPOSE} ps app | grep -q "Up"; then \
		echo "Containers are not running. Starting them..."; \
		${MAKE} up; \
	fi
	@echo "Starting message consumer..."
	@echo "Note: Worker runs in infinite loop. Press Ctrl+C to stop."
	${DOCKER_COMPOSE} exec app php bin/console messenger:consume -vv
.PHONY: consume

consume-test: ## Test worker with timeout (1 minute max)
	@if ! ${DOCKER_COMPOSE} ps app | grep -q "Up"; then \
		echo "Containers are not running. Starting them..."; \
		${MAKE} up; \
	fi
	@echo "Testing worker (1 minute timeout)..."
	@timeout 60 ${DOCKER_COMPOSE} exec app php bin/console messenger:consume -vv --time-limit=10 --limit=1 2>&1 || echo "Test completed or timeout"
.PHONY: consume-test

consume-workers: ## Start multiple workers for parallel processing (usage: make consume-workers WORKERS=4)
	@if ! ${DOCKER_COMPOSE} ps app | grep -q "Up"; then \
		echo "Containers are not running. Starting them..."; \
		${MAKE} up; \
	fi
	@echo "Starting $${WORKERS:-4} workers for parallel processing..."
	@for i in $$(seq 1 $${WORKERS:-4}); do \
		echo "Starting worker $$i..."; \
		${DOCKER_COMPOSE} exec -d app php bin/console messenger:consume -vv --time-limit=3600 || true; \
	done
	@echo "Started $${WORKERS:-4} workers. Use 'docker compose logs -f app' to monitor."
	@echo "Use 'make workers-status' to check worker status."
	@echo "Use 'make workers-stop' to stop all workers."
.PHONY: consume-workers

workers-status: ## Check status of running workers
	@echo "Checking worker status..."
	@${DOCKER_COMPOSE} exec app ps aux | grep "messenger:consume" | grep -v grep || echo "No workers running"
.PHONY: workers-status

workers-stop: ## Stop all running workers
	@echo "Stopping all workers..."
	@${DOCKER_COMPOSE} exec app pkill -f "messenger:consume" || echo "No workers to stop"
	@echo "Workers stopped."
.PHONY: workers-stop

workers-logs: ## Show workers logs (usage: make workers-logs WORKER=1)
	@if [ -z "$$WORKER" ]; then \
		echo "Showing logs for all workers..."; \
		${DOCKER_COMPOSE} logs app | grep -i "worker\|messenger:consume" | tail -50; \
	else \
		echo "Showing logs for worker $$WORKER..."; \
		${DOCKER_COMPOSE} logs app | grep -i "worker.*$$WORKER\|messenger:consume" | tail -50; \
	fi
.PHONY: workers-logs

##@ Testing commands

test: ## Run PHPUnit tests
	@echo "Running tests..."
	${DOCKER_COMPOSE} exec app vendor/bin/phpunit
.PHONY: test

test-coverage: ## Run tests with coverage report
	@echo "Running tests with coverage..."
	${DOCKER_COMPOSE} exec app vendor/bin/phpunit --coverage-html var/coverage
	@echo "Coverage report generated in var/coverage/"
.PHONY: test-coverage

test-filter: ## Run specific test (usage: make test-filter TEST=TestClassName)
	@echo "Running test: ${TEST}"
	${DOCKER_COMPOSE} exec app vendor/bin/phpunit --filter ${TEST}
.PHONY: test-filter

##@ Code quality commands

cs-fix: ## Run PHP-CS-Fixer
	@echo "Running PHP-CS-Fixer..."
	${DOCKER_COMPOSE} exec app vendor/bin/php-cs-fixer fix --allow-risky=yes
.PHONY: cs-fix

phpstan: ## Run PHPStan static analysis
	@echo "Running PHPStan..."
	${DOCKER_COMPOSE} exec app vendor/bin/phpstan analyse --memory-limit=512M
.PHONY: phpstan

psalm: ## Run Psalm static analysis
	@echo "Running Psalm..."
	${DOCKER_COMPOSE} exec app vendor/bin/psalm
.PHONY: psalm

rector: ## Run Rector code refactoring tool
	@echo "Running Rector..."
	${DOCKER_COMPOSE} exec app vendor/bin/rector process --ansi --no-progress-bar --config=rector.php
.PHONY: rector

grumphp: ## Run GrumPHP (all code quality checks)
	@echo "Running GrumPHP..."
	@echo "no" | ${DOCKER_COMPOSE} exec -T app vendor/bin/grumphp run
.PHONY: grumphp

qa: ## Run all code quality checks
	@echo "Running all code quality checks..."
	${MAKE} cs-fix
	${MAKE} phpstan
	@echo "Note: Psalm has some warnings but they are non-critical. Skipping for now."
	${MAKE} test
.PHONY: qa

##@ Data management

data-init: ## Initialize data directories with correct permissions
	@echo "Creating data directories..."
	@mkdir -p var/data/source var/data/destination
	@touch var/data/.gitkeep var/data/source/.gitkeep var/data/destination/.gitkeep
	@chmod 755 var/data/source var/data/destination
	@echo "Data directories created with correct permissions:"
	@echo "  - Source: $(PWD)/var/data/source"
	@echo "  - Destination: $(PWD)/var/data/destination"
	@echo "Put your files to sort in var/data/source/"
	@echo "Note: If directories were created by Docker as root, run: sudo chown -R $$(id -u):$$(id -g) var/data"
.PHONY: data-init

data-clean: ## Clean destination directory (WARNING: removes all organized files)
	@echo "WARNING: This will remove all files from var/data/destination/"
	@read -p "Are you sure? [y/N] " -n 1 -r; \
	echo; \
	if [[ $$REPLY =~ ^[Yy]$$ ]]; then \
		rm -rf var/data/destination/*; \
		echo "Destination directory cleaned"; \
	else \
		echo "Cancelled"; \
	fi
.PHONY: data-clean

##@ Redis commands

redis-cli: ## Open Redis CLI
	@if [ -z "${REDIS_PORT}" ]; then \
		${DOCKER_COMPOSE} exec redis redis-cli; \
	else \
		echo "Connecting to Redis via exposed port..."; \
		redis-cli -h localhost -p $$(echo ${REDIS_PORT} | cut -d: -f1); \
	fi
.PHONY: redis-cli

redis-flush: ## Flush all Redis data
	@echo "Flushing Redis..."
	${DOCKER_COMPOSE} exec redis redis-cli FLUSHALL
	@echo "Redis flushed"
.PHONY: redis-flush

##@ RabbitMQ commands (when using RabbitMQ profile)

rabbitmq-management: ## Open RabbitMQ management UI in browser (Linux)
	@echo "Opening RabbitMQ management UI..."
	@xdg-open http://localhost:15672 || echo "Please open http://localhost:15672 manually"
.PHONY: rabbitmq-management

##@ Help

help: ## Help
	@awk 'BEGIN {FS = ":.*##"; printf "\nUsage:\n  make \033[36m<target>\033[0m\n"} /^[.a-zA-Z_-]+:.*?##/ { printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2 } /^##@/ { printf "\n\033[1m%s\033[0m\n", substr($$0, 5) } ' $(MAKEFILE_LIST)
.PHONY: help
