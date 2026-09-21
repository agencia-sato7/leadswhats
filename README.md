# LEADSWHATS

LEADSWHATS e um SaaS B2B multiempresa para monitoramento passivo de WhatsApp, Revenue Intelligence, Auto-CRM e dashboards comerciais.

Stack atual:
- Backend: Laravel
- IA: Python + FastAPI + Pydantic
- Frontend: React + TypeScript + Vite
- Infra local: Docker Compose
- Cache: Redis
- Banco disponivel no Compose: PostgreSQL 16

## Requisitos

Antes de subir o projeto localmente, garanta:
- Docker e Docker Compose instalados
- Porta `9000` livre para a API
- Porta `8001` livre para o microserviço de IA
- Porta `5174` livre para o frontend
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

Para conectar o mesmo número no WhatsApp Business App e na Cloud API, use a **coexistência**. O formulário manual por token foi removido. Veja o [guia de configuração e operação](docs/WHATSAPP_COEXISTENCE.md).

No frontend fora do Docker, copie `frontend/.env.example` para `frontend/.env`:

```env
VITE_META_APP_ID=<id-publico-do-app>
VITE_META_COEXISTENCE_CONFIG_ID=1088502166986777
```

No Docker Compose, configure `META_APP_ID` e `META_COEXISTENCE_CONFIG_ID` no ambiente da raiz. O backend também fornece esses identificadores públicos à tela de configuração.

O `META_APP_SECRET` e o access token permanecem exclusivamente no backend e nunca devem usar o prefixo `VITE_`. A coexistência continua usando Embedded Signup, agora com o onboarding do WhatsApp Business App.

Antes de analisar conversas com o provider real, copie o arquivo de ambiente da raiz e configure a chave:

```bash
cp .env.example .env
```

```env
OPENAI_API_KEY=<sua-chave>
OPENAI_MODEL=gpt-4o-mini
```

A chave existe apenas no container `ai-service`; ela não é repassada ao Laravel.

O ambiente local deve usar `WHATSAPP_PROVIDER=fake`. Esse provider não abre sessão, não usa navegador e não realiza chamadas externas. Em `production`, a aplicação aceita exclusivamente `WHATSAPP_PROVIDER=meta_cloud`.

## Endpoints locais

- API Laravel: `http://localhost:9000`
- Swagger / OpenAPI Laravel: `http://localhost:8080/api/docs`
- AI Service: `http://localhost:8001`
- Health do AI Service: `http://localhost:8001/health`
- OpenAPI do AI Service: `http://localhost:8001/docs`
- Frontend: `http://localhost:5174`
- PostgreSQL do Compose: `localhost:5433`
- Redis do Compose: `localhost:6380`

## Inicialização e desempenho

O backend usa `artisan serve --no-reload` para respeitar `PHP_CLI_SERVER_WORKERS=4` no Laravel instalado. É um servidor de desenvolvimento; uma implantação de produção deve usar um servidor PHP apropriado.

Reiniciar o backend não deve regenerar `APP_KEY`, executar migrations ou seeds automaticamente. A chave existente protege dados criptografados: gere-a apenas no primeiro setup. Migrations e dados demo são operações explícitas, conforme os comandos abaixo.

O polling aguarda todas as chamadas do ciclo terminarem e mais 5 segundos antes de repetir, pausando quando a aba está oculta. Atualizações do dashboard em andamento são compartilhadas apenas para a mesma sessão, empresa e geração de dados. Respostas bem-sucedidas atualizam seus blocos sem apagar dados anteriores quando outro endpoint falha.

O cálculo de tempo comercial soma interseções diárias com expediente e almoço, em vez de percorrer cada minuto. Isso também corrige contagens em limites de expediente com segundos parciais.

Validação: `npm test` e `npm run build` no frontend; testes PHPUnit no backend com `APP_ENV=testing`, `DB_CONNECTION=sqlite` e `DB_DATABASE=:memory:`. Nunca execute testes de reset contra o PostgreSQL com dados reais.

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

## Desconectar WhatsApp

Em **Configurações → Integração WhatsApp**, admin/gestor da própria empresa pode escolher **Desconectar WhatsApp** e confirmar. A operação `POST /api/v1/settings/whatsapp/disconnect` cancela a assinatura do aplicativo no WABA e remove apenas o vínculo local (credenciais e estado de sincronização), preservando leads, contatos e conversas. O WhatsApp Business do celular não é excluído; não se trata de revogação global das permissões na Meta.

### Usuários e controle de acesso

O `platform_admin` possui no painel global as áreas **Usuários** e **Controle de acesso**. Perfis combinam permissões por página/ação com escopo de dados (`own` ou `company`). Os modelos Administrador, Gestor, SDR e Conector WhatsApp são criados globalmente e copiados para cada clínica; mudanças em modelos só chegam às cópias após aplicação explícita.

- Administrador da clínica é um perfil protegido e sempre possui acesso total.
- Conector WhatsApp acessa somente a página de integração e não pode receber leads.
- Usuários removidos são desativados, têm os tokens revogados e preservam seu histórico.
- Novos usuários recebem senha temporária e ficam bloqueados nas demais APIs até `POST /api/v1/auth/change-password`.
- Mudanças de usuários, perfis, permissões e senhas são registradas em `access_audit_logs`, sem armazenar credenciais.

Se a Meta falhar ou não confirmar `success=true`, o vínculo é preservado para nova tentativa. Contas WABA usadas por outra empresa são bloqueadas para evitar interrupção de terceiros. Sem vínculo, a operação retorna sucesso sem chamada externa. É possível conectar novamente pelo fluxo de coexistência. Jobs pendentes associados ao ID antigo são descartados quando executados com a versão atualizada dos workers; trabalhos já em execução podem terminar. No deploy, recarregue os workers de forma controlada para carregar a proteção nova. Os testes usam SQLite em memória e `Http::fake`, nunca uma conexão real.

## Conversation Intelligence

O comando `leadswhats:demo-bootstrap` cria cinco conversas comerciais completas sem análises pré-gravadas. Entre como gestor, abra **Conversation Intelligence** e use **Analisar com IA** para criar o primeiro snapshot; **Reanalisar** cria uma nova versão sem apagar as anteriores.

O Laravel usa `PythonConversationAnalyzer` quando `CONVERSATION_ANALYZER=python` e chama exclusivamente o `ai-service`. O microserviço valida entrada e saída com Pydantic e é o único componente que conhece `OPENAI_API_KEY` e `OPENAI_MODEL`.

Não existe fallback silencioso. Falha ou ausência de configuração do provider retorna erro controlado. O `FakeConversationAnalyzer` só pode ser usado em `local/testing` quando `CONVERSATION_ANALYZER=fake` for definido explicitamente; a suíte Laravel faz isso no `phpunit.xml`.

## Inteligencia da Campanha

A area **Inteligencia da Campanha** compara periodos fechados de ate 90 dias. Ela separa leads novos de leads antigos resgatados, compara o volume com o bloco imediatamente anterior de igual duracao e avalia a qualidade do atendimento com a mesma rubrica da Conversation Intelligence.

Os snapshots concluidos e as evidencias por lead/dia sao imutaveis. Repetir um periodo sem alteracao retorna o snapshot existente; ampliar ou sobrepor o intervalo reaproveita evidencias cujo hash de entrada continua valido.

O processamento usa a fila `campaign-intelligence`. No Compose, o servico `campaign-worker` inicia automaticamente. Fora do Compose, mantenha um worker ativo:

```bash
php artisan queue:work --queue=campaign-intelligence --tries=3 --timeout=600
```

Os endpoints ficam em `/api/v1/intelligence/campaign-reports`. Leitura e historico sao restritos a admin/gestor e ao contexto somente leitura da agencia; somente admin/gestor da propria empresa podem solicitar uma nova analise.

Para preparar uma campanha local completa para apresentação (período fechado de sete dias, volume comparativo, lead resgatado, evidências e relatório concluído), execute:

```bash
docker compose exec backend php artisan leadswhats:demo-campaign
```

O comando também garante os dados base da empresa demo e usa um analisador determinístico, sem consumir uma API externa. Ele é bloqueado em `production`.

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

AI Service, sem chamadas externas:

```bash
docker compose exec ai-service pytest -q
```

Build do frontend:

```bash
docker compose exec frontend sh -lc 'cd /app && npm run build'
```

Tipagem e testes do frontend:

```bash
docker compose exec frontend sh -lc 'cd /app && npm run typecheck'
docker compose exec frontend sh -lc 'cd /app && npm test'
```

Validacao do OpenAPI:

```bash
jq empty backend/storage/api-docs/openapi.json
```

## Observacoes importantes

- O projeto foi pensado para operacao multi-tenant por `company_id`.
- O monitoramento de WhatsApp e passivo: o sistema nao deve enviar mensagens comerciais para leads.
- A tela de Conversation Intelligence exibe mensagens somente para leitura e nunca movimenta o Kanban automaticamente.
- O perfil `sdr` nao pode acessar dados de outros vendedores nem alterar configuracoes estrategicas.
- O `docker-compose.yml` atual expoe PostgreSQL e Redis para desenvolvimento local.
- O backend local hoje sobe com a configuracao definida no proprio `docker-compose.yml`. Se o time decidir mudar a conexao padrao do banco, atualize esse arquivo e as variaveis de ambiente em conjunto.

## Webhook fake de ingestao local

Endpoint:

```text
POST /api/v1/webhooks/whatsapp
```

Esse endpoint existe somente fora de `production`. Eventos reais de produção entram exclusivamente pelo webhook oficial `POST /api/v1/webhooks/whatsapp/meta`.

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
