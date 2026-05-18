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

## Bootstrap demo/local (idempotente)

Para preparar dados mínimos operacionais do MVP local:

```bash
docker compose exec backend php artisan migrate
docker compose exec backend php artisan leadswhats:demo-bootstrap
```

Reset completo de desenvolvimento:

```bash
docker compose exec backend php artisan migrate:fresh
docker compose exec backend php artisan leadswhats:demo-bootstrap
```

Usuários demo:
- `admin@leadswhats.local` / `12345678` (`admin`)
- `gestor@empresa.local` / `12345678` (`gestor`)
- `sdr@empresa.local` / `12345678` (`sdr`)

Observação:
- O comando configura `company_business_settings`, pipeline padrão e colunas padrão da empresa demo.
- O `webhook_token` é gerado/configurado para uso local/demo quando estiver vazio (formato `demo_<random>`).
- Após rodar o comando, a saída mostra apenas o sufixo mascarado do token (ex.: `****abcd`) para conferência rápida.
- Se precisar confirmar o token completo localmente, consulte `company_business_settings.webhook_token` da empresa `empresa-demo` no banco.

## Exemplo de webhook WhatsApp (idempotente)

```bash
curl -X POST http://localhost:8000/api/v1/webhooks/whatsapp \
  -H 'Content-Type: application/json' \
  -H 'X-Webhook-Token: <TOKEN_DEMO_GERADO>' \
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

Substitua `<TOKEN_DEMO_GERADO>` pelo token configurado para a empresa demo.

Se o mesmo evento for reenviado com o mesmo `company_slug + provider + external_message_id`,
a API retorna sucesso idempotente e não duplica mensagem, lead, conversa ou métricas.

## Observação

Se algum container não subir na primeira vez por download de imagem/dependências, rode `make up` novamente.
