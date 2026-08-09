# LEADSWHATS

LEADSWHATS e um SaaS B2B multiempresa para monitoramento passivo de WhatsApp, Revenue Intelligence, Auto-CRM e dashboards comerciais.

Stack atual:
- Backend: Laravel
- Frontend: React + TypeScript + Vite
- Infra local: Docker Compose
- Cache: Redis
- Banco disponivel no Compose: PostgreSQL 16

## Requisitos

Antes de subir o projeto localmente, garanta:
- Docker e Docker Compose instalados
- Porta `8000` livre para a API
- Porta `5173` livre para o frontend
- Porta `5433` livre para o PostgreSQL local do Compose
- Porta `6380` livre para o Redis local do Compose

## Subindo o projeto local

1. Clone o repositorio.
2. Entre na pasta do projeto.
3. Suba os containers:

```bash
make up
```

Alternativa sem `make`:

```bash
docker compose up -d
```

Na primeira subida, o ambiente instala dependencias do frontend e sobe os servicos definidos no `docker-compose.yml`.

## Endpoints locais

- API Laravel: `http://localhost:8000`
- Swagger / OpenAPI: `http://localhost:8000/api/docs`
- Frontend: `http://localhost:5173`
- PostgreSQL do Compose: `localhost:5433`
- Redis do Compose: `localhost:6380`

## Setup inicial apos subir os containers

Gere a chave da aplicacao, rode migrations e carregue os dados demo:

```bash
make key
make migrate
docker compose exec backend php artisan leadswhats:demo-bootstrap
docker compose exec backend php artisan leadswhats:doctor
```

## Usuarios de demo

- `platform@leadswhats.local` / `12345678`
- `admin@leadswhats.local` / `12345678`
- `gestor@empresa.local` / `12345678`
- `sdr@empresa.local` / `12345678`

Use essas credenciais apenas em ambiente local.

## Comandos uteis

```bash
make status
make logs
make backend-shell
make frontend-shell
make down
```

Reset completo do banco local:

```bash
docker compose exec backend php artisan migrate:fresh --seed
docker compose exec backend php artisan leadswhats:demo-bootstrap
```

## Testes e validacoes

Backend:

```bash
docker compose exec backend php artisan test
```

Build do frontend:

```bash
docker compose exec frontend sh -lc 'cd /app && npm run build'
```

Validacao do OpenAPI:

```bash
jq empty backend/storage/api-docs/openapi.json
```

## Observacoes importantes

- O projeto foi pensado para operacao multi-tenant por `company_id`.
- O monitoramento de WhatsApp e passivo: o sistema nao deve enviar mensagens comerciais para leads.
- O perfil `sdr` nao pode acessar dados de outros vendedores nem alterar configuracoes estrategicas.
- O `docker-compose.yml` atual expoe PostgreSQL e Redis para desenvolvimento local.
- O backend local hoje sobe com a configuracao definida no proprio `docker-compose.yml`. Se o time decidir mudar a conexao padrao do banco, atualize esse arquivo e as variaveis de ambiente em conjunto.

## Webhook de ingestao

Endpoint:

```text
POST /api/v1/webhooks/whatsapp
```

Autenticacao:
- Header `X-Webhook-Token`

Exemplo:

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
    "body": "Ola, vi voces no Instagram",
    "source": "instagram",
    "sent_at": "2026-05-06T10:00:00-03:00"
  }'
```

## Troubleshooting rapido

- Se o frontend abrir sem dados, rode `docker compose exec backend php artisan leadswhats:demo-bootstrap`.
- Se a API nao responder, confira `make status` e `make logs`.
- Se o webhook retornar `401`, valide o `X-Webhook-Token` da empresa demo.
- Se algum container falhar na primeira subida por download de imagem ou dependencias, rode `make up` novamente.

## Documentacao adicional

- Demo do MVP: [docs/MVP_DEMO.md](docs/MVP_DEMO.md)
- Integracao WhatsApp Cloud: [docs/WHATSAPP_META_CLOUD.md](docs/WHATSAPP_META_CLOUD.md)
- Roadmap ate fechar o Descritivo Logico: [docs/ROADMAP.md](docs/ROADMAP.md)
