up:
	DOCKER_BUILDKIT=0 docker compose up -d

down:
	docker compose down

logs:
	docker compose logs -f --tail=150

backend-shell:
	docker compose exec backend sh

frontend-shell:
	docker compose exec frontend sh

migrate:
	docker compose exec backend php artisan migrate

key:
	docker compose exec backend php artisan key:generate

status:
	docker compose ps -a
