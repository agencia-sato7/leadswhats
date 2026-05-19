# LEADSWHATS — Roteiro de Demo do MVP (sem IA)

## 1) Preparação do ambiente

```bash
docker compose up -d
docker compose exec backend php artisan migrate
docker compose exec backend php artisan leadswhats:demo-bootstrap
docker compose exec backend php artisan leadswhats:doctor
```

Opcional (sanidade frontend):

```bash
docker compose exec frontend sh -lc 'cd /app && npm run build'
```

## 2) Usuário para demo

Use o perfil gestor:
- Email: `gestor@empresa.local`
- Senha: `12345678`

Aviso de segurança:
- Credenciais demo são apenas para ambiente local/demo.
- Não utilizar em produção.
- Em ambientes reais, substituir imediatamente usuários e senhas padrão.

## 3) Conferir token do webhook demo/local

1. Rode novamente o bootstrap e veja o sufixo mascarado:

```bash
docker compose exec backend php artisan leadswhats:demo-bootstrap
```

2. Se precisar do token completo para cURL local, consulte `company_business_settings.webhook_token` para a empresa `empresa-demo`.

## 4) Criar lead e conversa via webhook inbound

```bash
curl -X POST http://localhost:8000/api/v1/webhooks/whatsapp \
  -H 'Content-Type: application/json' \
  -H 'X-Webhook-Token: <TOKEN_DEMO_GERADO>' \
  -d '{
    "company_slug": "empresa-demo",
    "phone": "(11) 98888-1111",
    "direction": "inbound",
    "provider": "whatsapp-cloud",
    "external_message_id": "wamid.demo.inbound.001",
    "channel": "text",
    "body": "Olá, quero saber mais",
    "source": "instagram",
    "sent_at": "2026-05-06T10:00:00-03:00"
  }'
```

Esperado:
- API retorna sucesso.
- `data.duplicated = false`.

## 5) Validar idempotência

Reenvie o mesmo payload (mesmo `external_message_id`).

Esperado:
- API continua retornando sucesso idempotente.
- `data.duplicated = true`.
- sem duplicar lead/conversa/mensagem.

## 6) Fluxo no frontend

1. Login como gestor (`http://localhost:5173`).
2. Ver o lead no Kanban.
3. Abrir Inbox e localizar a conversa criada.
4. Atribuir responsável pela Inbox.
5. Responder via Inbox (provider fake/local).
6. Abrir aba Auditoria e validar eventos:
   - abertura
   - envio de mensagem
   - mudança de responsável
7. Conferir checklist operacional.
8. Mover card de etapa no Kanban.
9. Abrir Contatos.
10. Exportar CSV.

## 7) Limitações conhecidas do MVP

- Sem IA.
- Provider WhatsApp fake/local por padrão.
- Envio real para WhatsApp depende de provider real futuro.
- Templates WhatsApp não implementados.
- Sem transcrição automática de áudio.
- Sem relatório PDF automático.

## 8) Troubleshooting rápido

- Frontend sem dados:
  rode `docker compose exec backend php artisan leadswhats:demo-bootstrap`.
- Banco sem seed/bootstrap:
  rode `docker compose exec backend php artisan migrate` e depois `docker compose exec backend php artisan leadswhats:demo-bootstrap`.
- Nenhum pipeline disponível no Kanban:
  rode `docker compose exec backend php artisan leadswhats:demo-bootstrap` e confirme pipeline default + colunas da empresa.
- `401` no webhook:
  valide `X-Webhook-Token` da empresa demo.
- Token webhook inválido:
  confirme o header `X-Webhook-Token`, use `<TOKEN_DEMO_GERADO>` e confira o token em `company_business_settings`.
- Provider fake em produção:
  `leadswhats:doctor` deve falhar em `WHATSAPP_PROVIDER_POLICY`.
- Sessão expirada:
  faça login novamente.
- Backend/API fora do ar:
  verifique `docker compose ps`, `docker compose logs backend` e suba com `docker compose up -d`.

## 9) Acesso rápido

- Frontend: `http://localhost:5173`
- API: `http://localhost:8000`
- API Docs: `http://localhost:8000/api/docs`
