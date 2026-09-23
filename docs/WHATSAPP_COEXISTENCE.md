# WhatsApp em coexistência

Coexistência mantém o mesmo número no WhatsApp Business App e na Cloud API. A autorização continua usando Embedded Signup, configurado para onboarding de usuários do Business App; não é uma conexão por token digitado pelo usuário.

## Configuração na Meta

- Use um aplicativo elegível como Tech Provider/Solution Partner e uma conta WhatsApp Business App compatível e elegível.
- Confira se a configuração **1088502166986777** pertence ao aplicativo e habilita o onboarding do WhatsApp Business App. O ID fornecido foi configurado no projeto; sua elegibilidade não pode ser validada por testes locais.
- Configure os domínios HTTPS permitidos no Facebook Login for Business.
- Configure o callback público `GET/POST /api/v1/webhooks/whatsapp/meta` e o verify token. Assine os campos necessários, incluindo `messages`, `smb_message_echoes`, `history` e `account_update`.
- A autorização e o compartilhamento de histórico devem ser confirmados pelo titular no WhatsApp Business App. A disponibilidade de histórico depende do consentimento e das restrições da Meta.

Referência oficial: https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/onboarding-business-app-users

## Ambiente

No arquivo de ambiente do backend, configure os valores reais do seu aplicativo:

```dotenv
WHATSAPP_PROVIDER=meta_cloud
META_APP_ID=
META_APP_SECRET=
WHATSAPP_CLOUD_APP_SECRET=
WHATSAPP_CLOUD_WEBHOOK_VERIFY_TOKEN=
META_GRAPH_API_VERSION=v25.0
META_COEXISTENCE_CONFIG_ID=1088502166986777
META_COEXISTENCE_FEATURE_TYPE=whatsapp_business_app_onboarding
META_COEXISTENCE_SESSION_INFO_VERSION=3
META_COEXISTENCE_AUTO_SYNC=true
QUEUE_CONNECTION=database
```

`META_APP_SECRET` é usado na troca do código; `WHATSAPP_CLOUD_APP_SECRET` deve conter o segredo do mesmo aplicativo para validar a assinatura do webhook. Nunca exponha segredos em variáveis `VITE_*`. Preserve a `APP_KEY` existente: trocar essa chave impede a leitura dos tokens criptografados já salvos.

Não configure `META_REDIRECT_URI` para este fluxo. O Embedded Signup via SDK troca o código sem esse parâmetro; URLs temporárias copiadas do painel da Meta, especialmente as que contêm `nonce`, causam o erro OAuth `100/36008`.

Para executar o frontend fora do Compose, copie o exemplo de ambiente do frontend e configure `VITE_META_APP_ID` e `VITE_META_COEXISTENCE_CONFIG_ID`. No Compose, as variáveis públicas vêm de `META_APP_ID` e `META_COEXISTENCE_CONFIG_ID` no ambiente da raiz. A tela também recebe configuração pública do backend.

Depois de publicar as alterações, execute na raiz do projeto:

```bash
docker compose exec backend php artisan migrate --force
docker compose exec backend php artisan config:clear
```

Recompile/reinicie o frontend após alterar variáveis Vite. Mantenha um worker supervisionado para processar o histórico; para diagnóstico pode executá-lo em primeiro plano:

```bash
docker compose exec backend php artisan queue:work --tries=3 --timeout=300
```

Não execute `migrate:fresh` nem gere uma nova `APP_KEY` em uma instalação existente.

## Operação e API

Um administrador ou gestor usa **Conectar WhatsApp**, autoriza a conta existente e confirma a conexão no Business App. O frontend combina o código de autorização com o evento de conclusão e o envia ao backend. O backend troca o código, armazena o token criptografado, assina o app no WABA e solicita a sincronização.

- `GET /api/v1/settings/whatsapp`: configuração e estados da integração, sem access token.
- `POST /api/v1/settings/whatsapp/coexistence/complete`: conclusão autenticada do onboarding (`code` e `coexistence`). Consulte o OpenAPI para o payload completo.
- `POST /api/v1/settings/whatsapp/coexistence/sync`: solicita sincronização com `sync_type` igual a `contacts`, `history` ou `both`.

O formulário manual, `PUT /api/v1/settings/whatsapp` e a antiga rota `/embedded-signup/complete` foram removidos intencionalmente. Clientes dessas rotas precisam migrar. Credenciais de integrações existentes não são apagadas. SDRs não podem administrar a conexão; o tenant é obtido do contexto autenticado.

## Captura e limitações

- Mensagens inbound e estados de entrega mantêm o processamento existente.
- `smb_message_echoes`: textos enviados pelo Business App são registrados como outbound, sem enviar novas mensagens. Ecos de mídia, edição e revogação não são importados por este adaptador.
- `history`: importação de textos em fila, com deduplicação. O histórico não dispara as automações de resgate/AI-Kanban e não deve regredir os timestamps de atividade. Placeholders de mídia não são importados.
- O pedido de sincronização aceito não significa importação concluída. Confira os estados retornados pela API e os logs/failed jobs; não há garantia de disponibilidade de todo o histórico ou de importação completa da agenda de contatos.
- Falha em `subscribed_apps` marca a conexão como erro. Falha no pedido de sincronização é registrada separadamente.
- A integração não inicia mensagens comerciais como parte do onboarding ou da importação.

## Validação

```bash
docker compose exec backend php artisan test
docker compose exec frontend npm test
docker compose exec frontend npm run build
```

Os testes usam respostas simuladas da Meta. A validação ponta a ponta requer o aplicativo configurado, autorização real no navegador e recebimento de webhooks assinados. Confirme inbound, echo outbound e isolamento entre empresas antes de disponibilizar a integração.
