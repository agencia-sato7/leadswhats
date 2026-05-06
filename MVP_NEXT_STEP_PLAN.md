# LEADSWHATS — Plano Técnico da Próxima Etapa do MVP

Objetivo desta etapa:
Fortalecer o núcleo do webhook e das regras de negócio antes de implementar IA, Copiloto, Gamificação ou Benchmarking.

---

## Ordem recomendada (PRs pequenos)

## ~~PR 1 — Idempotência do webhook (base de confiabilidade)~~ ✅
Objetivo: garantir que reentrega/replay do webhook não duplique efeitos.

Escopo:
- Definir chave idempotente por mensagem (prioridade):
  1. `external_message_id` (quando vier)
  2. fallback hash determinístico (`company + phone + direction + sent_at + body/audio`)
- Persistir e validar essa chave antes de processar.
- Retornar resposta estável para duplicatas (ex.: `200` com `duplicated: true`).

Arquivos prováveis:
- `backend/database/migrations/*_add_idempotency_key_to_messages.php`
- `backend/app/Models/Message.php`
- `backend/app/Services/WhatsappIngestionService.php`
- `backend/app/Http/Controllers/Api/WhatsappWebhookController.php`
- `backend/storage/api-docs/openapi.json`

Commits sugeridos:
1. `feat(webhook): add message idempotency key and unique index`
2. `feat(webhook): enforce idempotent ingest in whatsapp service`
3. `docs(api): document duplicate webhook behavior`

Riscos:
- Colisão de hash fallback.
- Mensagens antigas sem `external_message_id`.
- Concorrência (duas requisições iguais em paralelo) -> tratar com `unique index + try/catch`.

---

## ~~PR 2 — Configurações comerciais por empresa (fonte única de regra)~~ ✅
Objetivo: centralizar horários/regras comerciais por tenant e remover hardcodes.

Escopo:
- Criar tabela de configuração por empresa (`company_business_settings` ou similar):
  - timezone, início/fim expediente, almoço, dias úteis
  - janela de lead repetido (ex.: 90 dias)
  - janela de vácuo (ex.: 24h)
  - token do webhook por empresa (opcional, recomendado)
- Criar service para resolver config efetiva por `company_id`.

Arquivos prováveis:
- `backend/database/migrations/*_create_company_business_settings_table.php`
- `backend/app/Models/CompanyBusinessSetting.php`
- `backend/app/Services/CompanySettingsService.php`
- `backend/database/seeders/DatabaseSeeder.php` (defaults)

Commits sugeridos:
1. `feat(settings): add company business settings table and defaults`
2. `feat(settings): add company settings service`
3. `chore(seed): seed default settings for demo company`

Riscos:
- Divergência entre campos já existentes em `companies` e nova tabela.
- Timezone inconsistente se não padronizar parsing.

---

## ~~PR 3 — Refatoração das regras atuais para Services (limpeza de domínio)~~ ✅
Objetivo: deixar controllers magros e regras versionáveis/testáveis.

Escopo:
- Extrair/melhorar serviços:
  - `LeadClassificationService` (`novo/repetido/existente`)
  - `BusinessTimeService` (primeira resposta em horário comercial)
  - `RescueDetectionService` (vácuo/resgate)
  - `SourceClassificationService` (auto/manual + auditoria)
  - `DashboardMetricsService` (agregações)
- Controller apenas orquestra request/response.

Arquivos prováveis:
- `backend/app/Services/*`
- `backend/app/Http/Controllers/Api/WhatsappWebhookController.php`
- `backend/app/Http/Controllers/Api/DashboardController.php`
- `backend/app/Http/Controllers/Api/LeadSourceController.php`

Commits sugeridos:
1. `refactor(domain): extract lead classification and rescue rules`
2. `refactor(domain): extract business time calculation`
3. `refactor(dashboard): move metrics aggregation to service`

Riscos:
- Regressão silenciosa nas métricas.
- Mudança de comportamento em edge cases (fim de semana/almoço).

---

## PR 4 — Testes automatizados das regras críticas (proteção de MVP)
Objetivo: blindar núcleo antes de evoluir produto.

Escopo mínimo de testes (Feature + Unit):
1. Idempotência:
- mesma mensagem 2x -> 1 `message`, sem duplicar contadores/efeitos.
2. Classificação de lead:
- novo, repetido (janela), existente.
3. Primeira resposta:
- dentro/fora expediente, atravessando almoço e dia seguinte.
4. Resgate:
- outbound após >24h sem resposta inbound.
5. Permissões:
- `sdr` não classifica origem; `gestor/admin` classifica.
6. Multi-tenant:
- não vazar dados entre `company_id`.

Arquivos prováveis:
- `backend/tests/Feature/*Webhook*.php`
- `backend/tests/Feature/*LeadSource*.php`
- `backend/tests/Feature/*Dashboard*.php`
- `backend/tests/Unit/*Service*.php`
- `backend/tests/TestCase.php` (helpers/factories se precisar)
- `backend/database/factories/*`

Commits sugeridos:
1. `test(webhook): add idempotency and classification feature tests`
2. `test(domain): add business time and rescue unit tests`
3. `test(authz): enforce role and tenant boundaries`

Riscos:
- Testes frágeis por tempo/data -> usar relógio controlado (`Carbon::setTestNow`).
- Seed acoplando testes -> preferir factories.

---

## PR 5 — Atualização OpenAPI e contratos finais da etapa
Objetivo: manter contrato confiável para frontend e QA.

Escopo:
- Documentar:
  - comportamento idempotente (resposta de duplicata)
  - novos campos de configuração/impacto
  - possíveis códigos de erro/regras de permissão
- Incluir exemplos reais de payload/response para webhook e dashboard.

Arquivos prováveis:
- `backend/storage/api-docs/openapi.json`
- opcional: `README.md` (se novos comandos/processos)

Commits sugeridos:
1. `docs(api): update openapi for webhook idempotency and settings`
2. `docs(readme): add mvp validation notes`

Riscos:
- Drift entre implementação e OpenAPI -> validar no fim de cada PR.

---

## Sequência final
1. ~~PR 1 Idempotência~~ ✅
2. ~~PR 2 Config por empresa~~ ✅
3. ~~PR 3 Refatoração para services~~ ✅
4. PR 4 Testes automatizados
5. PR 5 OpenAPI/Docs finais

Essa ordem minimiza risco: primeiro confiabilidade da ingestão, depois parametrização, depois refactor, cobertura de testes e fechamento de contrato.
