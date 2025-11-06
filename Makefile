DOCKER:=$(shell which docker)
PWD:=$(shell pwd)

DOCKER_COMPOSE:=${DOCKER} compose

.DEFAULT_GOAL:= help

##@ Docker compose commands

up: ## Start up application with Redis (default)
	@echo "Starting application with Redis..."
	${DOCKER_COMPOSE} up -d --remove-orphans
	@echo "Application is running. Redis is available at localhost:6379"
.PHONY: up

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
	${DOCKER_COMPOSE} build --no-cache
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
	@echo "Running file sorting application..."
	${DOCKER_COMPOSE} exec app php index.php
.PHONY: run

run-dry-run: ## Run the application in dry-run mode (no files moved)
	@echo "Running file sorting application in dry-run mode..."
	${DOCKER_COMPOSE} exec -e DRY_RUN=true app php index.php
.PHONY: run-dry-run

scan: ## Scan files and dispatch events (without organizing)
	@echo "Scanning files..."
	${DOCKER_COMPOSE} exec app php bin/console scan:files /var/data/source
.PHONY: scan

consume: ## Consume messages from queue (run worker)
	@echo "Starting message consumer..."
	${DOCKER_COMPOSE} exec app php bin/console messenger:consume async -vv
.PHONY: consume

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
	${DOCKER_COMPOSE} exec app vendor/bin/phpstan analyse
.PHONY: phpstan

psalm: ## Run Psalm static analysis
	@echo "Running Psalm..."
	${DOCKER_COMPOSE} exec app vendor/bin/psalm
.PHONY: psalm

grumphp: ## Run GrumPHP (all code quality checks)
	@echo "Running GrumPHP..."
	${DOCKER_COMPOSE} exec app vendor/bin/grumphp run
.PHONY: grumphp

qa: ## Run all code quality checks
	@echo "Running all code quality checks..."
	${MAKE} cs-fix
	${MAKE} phpstan
	${MAKE} psalm
	${MAKE} test
.PHONY: qa

##@ Data management

data-init: ## Initialize data directories
	@echo "Creating data directories..."
	@mkdir -p var/data/source var/data/destination
	@touch var/data/.gitkeep var/data/source/.gitkeep var/data/destination/.gitkeep
	@echo "Data directories created:"
	@echo "  - Source: $(PWD)/var/data/source"
	@echo "  - Destination: $(PWD)/var/data/destination"
	@echo "Put your files to sort in var/data/source/"
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
	${DOCKER_COMPOSE} exec redis redis-cli
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
