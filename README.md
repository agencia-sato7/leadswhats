# LEADSWHATS (Docker)

Stack:
- Backend: Laravel (container `laravelsail/php84-composer`)
- Frontend: React + TypeScript (Vite)
- DB: PostgreSQL 16
- Cache/Queue: Redis 7

## Subir ambiente

```bash
make up
```

Na primeira subida:
- o backend cria automaticamente o projeto Laravel em `./backend`
- o frontend cria automaticamente o Vite React TS em `./frontend`

## Acessos

- API/Laravel: http://localhost:8000
- Frontend React: http://localhost:5173
- PostgreSQL: `localhost:5433` (`leadswhats/leadswhats`)
- Redis: `localhost:6380`

## Comandos úteis

```bash
make status
make logs
make backend-shell
make frontend-shell
make key
make migrate
make down
```

## Observação

Se algum container não subir na primeira vez por download de imagem/dependências, rode `make up` novamente.
