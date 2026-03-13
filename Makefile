DOCKER_RUN = docker compose run --rm app
DOCKER_EXEC = docker compose exec app

.PHONY: up down build setup migrate seed monitor work horizon serve logs test shell

## === Setup ===

build:
	docker compose build

up:
	docker compose up -d

down:
	docker compose down

setup: build up
	@echo "Waiting for services..."
	@sleep 8
	$(DOCKER_EXEC) php artisan vendor:publish --tag=config --force || true
	$(DOCKER_EXEC) php artisan vendor:publish --tag=migrations --force || true
	$(DOCKER_EXEC) php artisan vendor:publish --tag=views --force || true
	$(DOCKER_EXEC) php artisan migrate --force
	@echo ""
	@echo "=== Setup complete! ==="
	@echo "  make seed       - Create demo data"
	@echo "  make work       - Start distribution worker"
	@echo "  make monitor    - Monitor queue status"
	@echo "  make horizon    - Start Horizon"
	@echo ""

migrate:
	$(DOCKER_EXEC) php artisan migrate --force

## === Demo ===

seed:
	$(DOCKER_EXEC) php artisan demo:seed

seed-large:
	$(DOCKER_EXEC) php artisan demo:seed --shops=10 --orders=100 --products=50

## === Workers ===

work:
	$(DOCKER_EXEC) php artisan distribution:work

horizon:
	$(DOCKER_EXEC) php artisan horizon

## === Monitor ===

monitor:
	$(DOCKER_EXEC) php artisan distribution:monitor

monitor-watch:
	$(DOCKER_EXEC) php artisan distribution:monitor --watch=3

monitor-detail:
	$(DOCKER_EXEC) php artisan distribution:monitor --detail --failures

monitor-json:
	$(DOCKER_EXEC) php artisan distribution:monitor --json

## === Server ===

serve:
	$(DOCKER_EXEC) php artisan serve --host=0.0.0.0 --port=8088

## === Utility ===

shell:
	$(DOCKER_EXEC) bash

logs:
	docker compose logs -f app

test:
	$(DOCKER_EXEC) php artisan test
