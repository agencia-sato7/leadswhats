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

### P0.1 — Transcrição real de áudio no whatsapp-qr-service

**Objetivo:** popular `audio_transcript` de verdade. Hoje qualquer áudio vira
o texto literal `"[Áudio]"` — a IA (Kanban e, depois, o scoring) fica cega
pra essa parte da conversa.

**Escopo técnico:**
- `whatsapp-qr-service/src/index.js`: quando `msg.audioMessage` existir,
  baixar a mídia via `downloadMediaMessage(message, 'buffer', {}, { logger,
  reuploadRequest: socket.updateMediaMessage })` (Baileys já expõe essa
  função).
- Chamar `POST https://api.openai.com/v1/audio/transcriptions` (Whisper,
  multipart/form-data) com uma nova env var `OPENAI_API_KEY` no
  `whatsapp-qr-service` (hoje só existe no backend Laravel).
- Incluir `audio_transcript` e `channel: 'audio'` no payload do webhook —
  `buildMessagePayload()` hoje não manda nenhum dos dois.
- `BaileysWebhookController::ingest` (casos `message_received` e
  `history_synced`): parar de hardcodar `"channel" => "text"`; repassar
  `data.channel` e `data.audio_transcript` do payload recebido.
- `docker-compose.yml`: adicionar `OPENAI_API_KEY` no `environment` do
  serviço `whatsapp-qr-service`.

**Critério de pronto:** mandar um áudio de teste pelo WhatsApp conectado
resulta em `messages.channel = 'audio'` e `messages.audio_transcript`
preenchido com o texto real (não mais o literal `[Áudio]`).

---

### P0.2 — Scoring de atendimento por IA (0–100) — **o core do produto**

**Objetivo:** nota 0–100 por conversa avaliando SPIN Selling, Encantamento
Disney, gatilho mental/escassez e contorno de objeção, com uma dica de
melhoria em texto explicando o porquê da nota.

**Escopo técnico:**
- Migration `create_conversation_quality_scores_table`: `id, company_id,
  conversation_id` (FK `conversations`, cascade), `lead_id` (FK `leads`,
  cascade), `score` (unsignedTinyInteger 0–100), `criteria` (json — nota por
  critério), `tips` (text), `raw_response` (json, auditoria), `scored_at`,
  timestamps. Index `['company_id', 'scored_at']`.
- Model `ConversationQualityScore`.
- Serviço `app/Services/Domain/ConversationQualityScoringService.php`: monta
  o transcript completo da conversa (mensagens + `audio_transcript`,
  ordenadas por `sent_at`), chama a IA com prompt estruturado pelos 4
  critérios do PDF, grava o score. Seguir o padrão de chamada OpenAI já usado
  em `OpenAiIntelligenceService` (`Http::` + `env('OPENAI_API_KEY')` +
  fallback em erro).
- **Decisão em aberto — gatilho de "conversa finalizada":** hoje nenhuma
  conversa é fechada explicitamente (`Conversation.status` fica sempre
  `'active'`, confirmado em `ConversationResolverService`). Duas opções:
  - (a) job periódico que pontua conversas sem mensagem nova há N horas
    (proxy de "acabou"), ou
  - (b) **recomendado** — pontuar quando o Kanban move o lead pra uma coluna
    terminal (`kanban_columns.is_terminal`, campo que já existe e já é
    avaliado pelo `AiKanbanMovementService`). Menos ambíguo que um timeout.
- Endpoint `GET /api/v1/performance/scores` (role: admin,gestor,sdr — SDR só
  vê o próprio, mesmo padrão do dashboard).
- Frontend: nova aba/seção "Performance" mostrando nota + dica por conversa.

**Critério de pronto:** mover um cartão pro estágio terminal gera uma linha
em `conversation_quality_scores`; SDR só vê as próprias notas, gestor vê a
equipe (teste de isolamento por role/tenant, no padrão de
`DashboardMetricsServiceTest`).

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

### P3.1 — Placar de contatos salvos

**Escopo técnico:**
- `whatsapp-qr-service`: no handler de `contacts.upsert`/`contacts.update`
  (já existe, adicionado pra resolver LID), quando `contact.name` estiver
  presente (contato salvo na agenda, diferente de `contact.notify`), mandar
  um novo evento de webhook `contact_saved_status` com `{phone, is_saved:
  true}`.
- Migration: coluna `is_saved_in_phone` (boolean, nullable) em `leads`.
- `BaileysWebhookController`: novo `case` pro evento.
- `ContactController`/`ContactDirectoryService`: expor "salvou X, esqueceu
  Y" no endpoint de listagem.

**Critério de pronto:** contato salvo no celular aparece marcado; contagem
bate no endpoint de contatos.

---

### P3.2 — Auto-CRM: extração estruturada

**Escopo técnico:**
- Estender `AiRuleEvaluatorService` (ou novo serviço dedicado) pra, na
  mesma chamada de IA que decide a coluna do Kanban, também extrair fatos
  soltos (orçamento, prazo, objeção mencionada) em JSON.
- Gravar em `leads.metadata` (campo `json` que já existe) sob uma chave
  dedicada, ex. `metadata->auto_crm`.
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
