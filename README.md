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

## Exemplo de webhook WhatsApp (idempotente)

```bash
curl -X POST http://localhost:8000/api/v1/webhooks/whatsapp \
  -H 'Content-Type: application/json' \
  -H 'X-Webhook-Token: leadswhats-dev-token' \
  -d '{
    "company_slug": "empresa-demo",
    "phone": "(11) 98888-1111",
    "direction": "inbound",
    "provider": "whatsapp-cloud",
    "external_message_id": "wamid.1234567890",
    "channel": "text",
    "body": "Olá, vi vocês no Instagram",
    "source": "instagram",
    "sent_at": "2026-05-06T10:00:00-03:00"
  }'
```

Se o mesmo evento for reenviado com o mesmo `company_slug + provider + external_message_id`,
a API retorna sucesso idempotente e não duplica mensagem, lead, conversa ou métricas.

## Observação

Se algum container não subir na primeira vez por download de imagem/dependências, rode `make up` novamente.
