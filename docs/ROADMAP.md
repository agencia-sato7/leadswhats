# Roadmap — Fechando o gap com o Descritivo Lógico

Este documento quebra em tasks concretas o que falta para o código bater com
`Descritivo_Lógico_do_Projeto_—_LEADSWHATS.pdf` (raiz do repo). Cada task tem
objetivo, escopo técnico (arquivos/tabelas envolvidos) e critério de pronto.

A ordem das fases (P0 → P3) é por **dependência técnica real**, não só por
importância — P0.2 é o item que o dono do produto chamou de razão do
LEADSWHATS existir, mas só é possível com P0.1 pronto antes.

Duas regras do descritivo que já foram corrigidas no código (não precisam de
task): **leitura passiva** (o Inbox não envia mais mensagem pro lead) e
**privacidade do SDR** (dashboard já escopa por `owner_user_id`).

---

## P0 — Pré-requisitos do core

### P0.1 — Transcrição de áudio recebido pela API oficial da Meta

**Objetivo:** popular `audio_transcript` de verdade. Hoje qualquer áudio vira
o texto literal `"[Áudio]"` — a IA (Kanban e, depois, o scoring) fica cega
pra essa parte da conversa.

**Escopo técnico:**
- Receber o evento de mídia exclusivamente pelo webhook oficial da Meta.
- Baixar o áudio usando somente os endpoints oficiais da Graph API.
- Transcrever no backend e preencher `channel = 'audio'` e
  `audio_transcript`, preservando o payload oficial para auditoria.

**Critério de pronto:** mandar um áudio de teste pelo WhatsApp conectado
resulta em `messages.channel = 'audio'` e `messages.audio_transcript`
preenchido com o texto real (não mais o literal `[Áudio]`).

---

### P0.2 — Scoring de atendimento por IA (0–100) — **o core do produto**

**Objetivo:** nota 0–100 por conversa, com resumo, intenção, objeções,
pontos positivos, erros, sugestão, dados comerciais e recomendação de etapa.

**Escopo técnico:**
- `conversation_quality_scores` armazena snapshots imutáveis e versionados.
- `ConversationAnalyzer` define o contrato estruturado independente do
  provedor. `PythonConversationAnalyzer` chama o microserviço FastAPI e não
  contém credenciais nem SDK da OpenAI no Laravel.
- O `ai-service` usa Structured Outputs, valida entrada e saída com Pydantic
  e concentra `OPENAI_API_KEY`/`OPENAI_MODEL`.
- `FakeConversationAnalyzer` só é ativado explicitamente em local/testing e
  não realiza chamadas externas.
- `ConversationIntelligenceService` monta a transcrição completa, inclui
  `kanban_columns.rule_prompt` no contexto, valida a recomendação e grava um
  novo snapshot. A coluna é recomendada, nunca movimentada automaticamente.
- Endpoints de listagem, detalhe, resumo e análise ficam em
  `/api/v1/intelligence/*`, somente para `admin/gestor` e isolados por tenant.
- `ConversationIntelligencePage.tsx` mostra mensagens somente para leitura,
  análise atual e histórico de reanálises.
- Próxima etapa: adicionar observabilidade, métricas de custo/latência e
  avaliações de qualidade do prompt, sem fallback por palavras-chave.

**Critério de pronto estrutural:** gestor analisa ou reanalisa manualmente uma
conversa e recebe um novo snapshot; o histórico anterior e o Kanban permanecem
inalterados. O provedor real será conectado em uma etapa posterior.

---

## P1 — Consequências diretas do scoring

### P1.1 — Maiores Erros de Atendimento & Comportamento do Lead de Sucesso

**Objetivo:** dois textos de IA agregando padrões sobre os scorings já
existentes — depende 100% de P0.2 ter dados.

**Escopo técnico:**
- Serviço `app/Services/Domain/AttendanceInsightsService.php`: lê os
  últimos N dias de `conversation_quality_scores` (+ `criteria`), pede pra
  IA (a) os padrões de erro mais recorrentes, (b) o resumo de quem teve nota
  alta **e** fechou negócio (cruzar com a coluna Kanban terminal "Fechado").
- Cache com TTL de algumas horas (Laravel cache) pra não rodar IA a cada
  carregamento de tela.
- Novo bloco no `GET /api/v1/dashboard/summary` ou endpoint dedicado
  `GET /api/v1/dashboard/insights`.

**Critério de pronto:** dashboard mostra as duas listas/textos com dados
reais de uma empresa com scorings suficientes.

---

### P1.2 — Disparo diário automático (18h30)

**Objetivo:** todo dia às 18h30 (hora local da empresa), congelar métricas
do dia, IA escreve resumo, gera PDF, envia no WhatsApp pessoal do gestor.

**Escopo técnico:**
- Migration: coluna `phone` em `users` — **não existe nenhum campo de
  telefone no usuário hoje**, pré-requisito de dados puro.
- `bootstrap/app.php`: adicionar `->withSchedule(function (Schedule
  $schedule) { ... })` (hoje ausente).
- 18h30 é por timezone de empresa (`company_business_settings.timezone`, já
  existe) — não dá pra usar um cron único. Rodar
  `Schedule::command('leadswhats:daily-report')->everyMinute()` e o próprio
  comando decidir, por empresa, se "agora" bate com as 18h30 locais dela.
- Novo Artisan command (seguir o padrão de `routes/console.php`, que hoje
  usa `Artisan::command()` inline em vez de classes de Command).
- Serviço `app/Services/Domain/DailyReportService.php`: reusa
  `DashboardMetricsService::summaryForCompany`, pede resumo em texto pra IA
  (bom / ruim / alerta de leads repetidos).
- Geração de PDF: **nenhuma lib de PDF no projeto hoje** — adicionar
  `composer require barryvdh/laravel-dompdf` (ou `spatie/laravel-pdf`).
- Envio: `WhatsAppProviderInterface::sendTextMessage` só manda texto hoje —
  precisa de um método novo (`sendDocument`/`sendMedia`) pra anexar o PDF.

**Critério de pronto:** `artisan leadswhats:daily-report` gera o PDF e chama
o provider pro número do gestor de uma empresa de teste (usar
`FakeWhatsAppProvider` no teste automatizado); teste confirma que só dispara
no minuto certo por timezone.

**Entregue (versão e-mail, sem PDF):**
- ai-service: `POST /v1/analyze/daily-report` (`DAILY_REPORT_PROMPT`,
  `daily-report-v1`) com `DailyReportRequest`/`DailyReportResponse` e
  `generate_daily_report` no provider.
- backend: contrato `DailyReportAnalyzer`, `PythonDailyReportAnalyzer`,
  `FakeDailyReportAnalyzer` (só local/testing) e binding em `AppServiceProvider`.
- Migration/model `daily_reports`: uma linha por empresa+data (idempotente),
  guardando métricas, rollup de qualidade, veredito, payload da IA,
  destinatários, `status` e `sent_at`.
- `app/Services/Domain/DailyReportService.php`: reusa
  `DashboardMetricsService::summaryForCompany`, agrega as pontuações de
  `conversation_quality_scores` do dia (média geral + médias por critério) e
  concentra a decisão de enviar/reenviar.
- Comando `leadswhats:daily-report` (`--company`, `--date`, `--send-time`,
  `--force`) em `routes/console.php`, registrado em `Schedule::command(...)`
  a cada 5 minutos (UTC) com `withoutOverlapping()`; o comando decide, por
  empresa, se a hora local bate com 18:30.
- Entrega por e-mail (`DailyReportMail` + view `emails.daily-report`) para o
  e-mail cadastrado no cadastro da clínica (`daily_report_recipient` em
  `company_business_settings`, preenchido na Central da Agência; sem e-mail o
  relatório fica `skipped`, sem chamada de IA); envio pela fila padrão
  (processada pelo `queue-worker`) e serviço `scheduler`
  (`php artisan schedule:work`) no `docker-compose.yml`.
- Testes: `tests/Feature/DailyReportCommandTest.php` (destinatário do cadastro,
  clínica sem e-mail → `skipped` + geração após cadastro, idempotência
  e `--force`, fuso local, isolamento entre empresas, conteúdo com as notas da IA)
  e casos novos em `ai-service/tests/test_api.py`.

**Ainda em aberto em relação ao escopo original:** entrega por WhatsApp exige
coluna de telefone em `users` e um método de documento no provider; PDF exige
adicionar uma lib (`barryvdh/laravel-dompdf` ou `spatie/laravel-pdf`) — hoje o
relatório vai em HTML no corpo do e-mail, sem anexo.

---

## P2 — Motivação e assistência ao vendedor

### P2.1 — Gamificação real (pontos, badges, leaderboard)

**Objetivo:** substituir o "ranking" atual (que é de criativo de anúncio,
não de vendedor) por gamificação de verdade.

**Escopo técnico:**
- Migrations: `point_events` (`id, company_id, user_id, points, reason,
  reference_type, reference_id, created_at`) e `badges` / `user_badges`
  (catálogo + concessões).
- Serviço `app/Services/Domain/GamificationService.php`: credita pontos nos
  mesmos eventos que já existem — resposta rápida
  (`FirstResponseCalculatorService`), resgate (`RescueDetectorService`),
  nota alta (P0.2).
- Endpoint `GET /api/v1/performance/leaderboard` (role: admin,gestor,sdr).
- Frontend: pódio/leaderboard na área de Performance.

**Critério de pronto:** responder rápido gera pontos visíveis no mesmo dia;
leaderboard isolado por empresa (SDR de uma empresa não vê pontuação de
outra).

---

### P2.2 — Copiloto em tempo real

**Decisão em aberto primeiro:** o Inbox hoje é só leitura (regra de leitura
passiva). "Sugerir resposta em tempo real" precisa de um lugar pra aparecer
sem virar canal de envio. Proposta: mostrar a sugestão dentro do Inbox como
texto copiável — **sem** botão de enviar pelo sistema, preservando a regra.

**Escopo técnico:**
- Endpoint `POST /api/v1/inbox/conversations/{id}/copilot-suggestion` (role:
  admin,gestor,sdr dono da conversa): pega as últimas mensagens, pede à IA
  uma sugestão de resposta pra objeção/situação atual.
- Frontend: botão "Sugerir resposta" no Inbox, mostra texto copiável.

**Critério de pronto:** gerar sugestão numa conversa com objeção de preço
retorna texto coerente; SDR só aciona em conversas próprias (mesma regra de
privacidade do resto do sistema).

---

## P3 — Complementos

### P3.1 — Auto-CRM: extração estruturada

**Escopo técnico:**
- Estender o adaptador real de `ConversationAnalyzer` para extrair fatos
  comerciais estruturados na mesma resposta da análise.
- Manter os fatos no snapshot em `conversation_quality_scores.commercial_data`;
  qualquer projeção futura em `leads.metadata` deve ser explícita e auditada.
- Frontend: mostrar esses fatos no cartão do Kanban / detalhe do lead.

**Critério de pronto:** "meu orçamento é 5 mil" resulta em
`lead.metadata.auto_crm.orcamento` preenchido e visível na tela.

---

### P3.3 — Benchmarking anônimo

**Escopo técnico:**
- Migration: coluna `industry`/`setor` em `companies` (não existe hoje).
- Onboarding/config: campo pro gestor informar o setor.
- Serviço de agregação cross-tenant anonimizada (média por setor, só com
  empresas que têm ≥ N amostras, pra não vazar dado individual).

**Critério de pronto:** com pelo menos 2 empresas do mesmo setor com dados,
o dashboard mostra "sua média X min, média do setor Y min" sem expor nomes
de outras empresas. **Bloqueado hoje por não ter massa suficiente de
clientes reais na base** — é o único item da lista que depende de dado de
produção, não só de código.
