# LEADSWHATS MVP (sem IA)

Stack:
- Backend: Laravel (container `laravelsail/php84-composer`)
- Frontend: React + TypeScript (Vite)
- DB: PostgreSQL 16
- Cache/Queue: Redis 7

## Subir ambiente local

```bash
# opção direta
docker compose up -d

# opção via atalho
make up
```

Na primeira subida:
- o backend cria automaticamente o projeto Laravel em `./backend`
- o frontend cria automaticamente o Vite React TS em `./frontend`

## Acessos

- API/Laravel: http://localhost:8000
- API Docs (Swagger): http://localhost:8000/api/docs
- Frontend React: http://localhost:5173
- PostgreSQL: `localhost:5433` (`leadswhats/leadswhats`)
- Redis: `localhost:6380`

## Setup inicial do MVP local

```bash
docker compose exec backend php artisan migrate
docker compose exec backend php artisan leadswhats:demo-bootstrap
docker compose exec backend php artisan leadswhats:doctor
```

Opcional (validação de build frontend):

```bash
docker compose exec frontend sh -lc 'cd /app && npm run build'
```

## Reset do ambiente local

```bash
docker compose exec backend php artisan migrate:fresh
docker compose exec backend php artisan leadswhats:demo-bootstrap
docker compose exec backend php artisan leadswhats:doctor
```

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

## Usuários demo (bootstrap)

- `admin@leadswhats.local` / `12345678` (`admin`)
- `gestor@empresa.local` / `12345678` (`gestor`)
- `sdr@empresa.local` / `12345678` (`sdr`)

Aviso de segurança:
- Essas credenciais são apenas para ambiente local/demo.
- Não use essas credenciais em produção.
- Em qualquer ambiente real, altere imediatamente usuários e senhas padrão.

## Webhook seguro (X-Webhook-Token)

Contrato de ingestão:
- endpoint: `POST /api/v1/webhooks/whatsapp`
- payload mantém `company_slug`
- autenticação por header `X-Webhook-Token`
- idempotência por `company_slug + provider + external_message_id`

Como obter/conferir token demo/local:
- O comando configura `company_business_settings`, pipeline padrão e colunas padrão da empresa demo.
- O `webhook_token` é gerado/configurado para uso local/demo quando estiver vazio (formato `demo_<random>`).
- Após rodar o comando, a saída mostra apenas o sufixo mascarado do token (ex.: `****abcd`) para conferência rápida.
- Se precisar confirmar o token completo localmente, consulte `company_business_settings.webhook_token` da empresa `empresa-demo` no banco.

Exemplo inbound:

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

Exemplo outbound (ingestão passiva de evento enviado):

```bash
curl -X POST http://localhost:8000/api/v1/webhooks/whatsapp \
  -H 'Content-Type: application/json' \
  -H 'X-Webhook-Token: <TOKEN_DEMO_GERADO>' \
  -d '{
    "company_slug": "empresa-demo",
    "phone": "(11) 98888-1111",
    "direction": "outbound",
    "provider": "whatsapp-cloud",
    "external_message_id": "wamid.outbound.1234567890",
    "channel": "text",
    "body": "Olá! Recebemos sua mensagem.",
    "source": "instagram",
    "sent_at": "2026-05-06T10:05:00-03:00"
  }'
```

Idempotência:
- Reenviar o mesmo `external_message_id` para a mesma empresa/provedor retorna sucesso idempotente.
- O campo `data.duplicated` vem `true` em replay duplicado.

## Fluxo de demo do MVP

Roteiro detalhado: `docs/MVP_DEMO.md`.

Resumo:
1. Login como `gestor`.
2. Disparar webhook inbound para criar lead/conversa.
3. Ver lead no Kanban.
4. Ver conversa na Inbox.
5. Atribuir responsável.
6. Responder na Inbox (provider fake/local).
7. Abrir aba Auditoria.
8. Ver checklist/riscos.
9. Mover card no Kanban.
10. Ver contatos e exportar CSV.

## Limitações conscientes do MVP

- Sem IA.
- Provider WhatsApp fake/local por padrão.
- Envio real WhatsApp depende de provider real futuro.
- Templates WhatsApp não implementados.
- Sem transcrição automática de áudio.
- Sem relatórios PDF automáticos.

## Comandos de validação

```bash
docker compose exec backend php artisan test
jq empty backend/storage/api-docs/openapi.json
docker compose exec frontend sh -lc 'cd /app && npm run build'
docker compose exec backend php artisan leadswhats:doctor
```

## Troubleshooting

- Frontend sem dados:
  rode `docker compose exec backend php artisan leadswhats:demo-bootstrap`.
- Banco sem seed/bootstrap:
  sintoma: telas sem dados e base sem empresas/usuários/settings.
  ação: rode `docker compose exec backend php artisan migrate` e depois `docker compose exec backend php artisan leadswhats:demo-bootstrap`.
- Nenhum pipeline disponível:
  sintoma: Kanban exibe ausência de pipeline.
  ação: rode `docker compose exec backend php artisan leadswhats:demo-bootstrap` e confirme pipeline default e colunas para a empresa.
- `401` no webhook:
  confira `X-Webhook-Token` da empresa em `company_business_settings.webhook_token`.
- Token webhook inválido:
  sintoma: `POST /api/v1/webhooks/whatsapp` retorna `401`.
  ação: confirme o header `X-Webhook-Token`, use `<TOKEN_DEMO_GERADO>` e valide o token configurado em `company_business_settings`.
- Provider fake em produção:
  `leadswhats:doctor` deve falhar com `WHATSAPP_PROVIDER_POLICY`.
- Sessão expirada:
  faça login novamente.
- Backend/API fora do ar:
  sintoma: frontend mostra erro de conexão com API.
  ação: execute `docker compose ps`, verifique `docker compose logs backend` e suba serviços com `docker compose up -d`.

## Observação final

Se algum container não subir na primeira vez por download de imagem/dependências, rode `make up` novamente.
