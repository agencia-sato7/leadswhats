# AGENTS.md

## 1) Visão geral do produto
LEADSWHATS é um SaaS B2B multiempresa (multi-tenant) para monitoramento passivo de WhatsApp, Revenue Intelligence, Auto-CRM e dashboards comerciais.

Princípios de produto:
- O sistema monitora conversas e métricas comerciais por empresa (`company_id`).
- O sistema **não deve enviar mensagens comerciais para leads**.
- A captura é passiva (leitura/ingestão), com análise, classificação e auditoria.

Perfis de acesso atuais:
- `admin`
- `gestor`
- `sdr`

Regra crítica:
- `sdr` não pode acessar dados de outros vendedores nem alterar configurações estratégicas.

---

## 2) Estrutura do repositório
Raiz:
- `docker-compose.yml`: orquestração local (backend, frontend, postgres, redis).
- `Makefile`: atalhos de desenvolvimento.
- `README.md`: setup rápido.
- `AGENTS.md`: este guia.

Backend Laravel:
- `backend/`
- `backend/app/Models`: modelos Eloquent.
- `backend/app/Http/Controllers`: controllers da API.
- `backend/app/Http/Middleware`: middlewares de autenticação/autorização.
- `backend/app/Services`: regras de negócio complexas (obrigatório para lógica relevante).
- `backend/app/Enums`: enums de domínio.
- `backend/database/migrations`: schema e evolução de banco.
- `backend/database/seeders`: dados iniciais.
- `backend/database/factories`: factories para testes/seeds.
- `backend/routes/api.php`: rotas versionadas da API (`/api/v1`).
- `backend/tests/Feature` e `backend/tests/Unit`: testes backend.

Frontend React (Vite + TS):
- `frontend/`
- `frontend/src`: app React, integração com API, tipos e telas.

OpenAPI / documentação API:
- `backend/storage/api-docs/openapi.json`
- UI Swagger servida em `/api/docs` via view Laravel.

---

## 3) Comandos principais
Subir ambiente Docker:
- `make up`

Parar ambiente:
- `make down`

Status dos containers:
- `make status`

Logs:
- `make logs`

Rodar migrations/seeds:
- `make migrate`
- Seed completo (quando necessário):
  - `docker compose exec backend php artisan db:seed`
- Reset completo (desenvolvimento):
  - `docker compose exec backend php artisan migrate:fresh --seed`

Rodar testes backend:
- `docker compose exec backend php artisan test`

Rodar frontend:
- já sobe via `make up` no container `frontend` (Vite em `http://localhost:5173`).

Shells úteis:
- `make backend-shell`
- `make frontend-shell`

---

## 4) Convenções obrigatórias
1. API sempre versionada em `/api/v1`.
2. Regra de negócio complexa deve ficar em `app/Services`, não diretamente em controller.
3. Toda feature nova deve respeitar multi-tenancy por `company_id`.
4. Alteração sensível deve ter auditoria quando aplicável (ex.: reclassificação de origem).
5. Perfis válidos: `admin`, `gestor`, `sdr`.
6. `sdr` não pode acessar dados de outros vendedores nem alterar configurações estratégicas.
7. Operações de gestão (ex.: classificação/reclassificação de origem) devem ser restritas a `gestor/admin`.

---

## 5) Checklist de “done”
Antes de concluir qualquer tarefa:
- Código implementado.
- Testes adicionados ou atualizados.
- OpenAPI atualizado se houver endpoint novo/alterado.
- README atualizado se houver novo comando ou fluxo operacional.
- Endpoints existentes não quebrados (backward compatibility do que já está em uso).

Checklist extra recomendado:
- Validar permissões por perfil (`admin`, `gestor`, `sdr`).
- Validar isolamento por `company_id` em consultas e writes.
