# Configuração e Operação do WhatsApp Cloud API

Este documento descreve como configurar, testar e operar a integração real com a API do WhatsApp Cloud no LEADSWHATS.

---

## 1. Variáveis de Ambiente Necessárias (.env)

Para ativar e validar a integração real com o WhatsApp Cloud API no backend, as seguintes variáveis de ambiente devem ser configuradas no arquivo `.env` do backend:

```env
# Provedor ativo de WhatsApp (opções: fake, meta_cloud, dynamic)
# Defina como meta_cloud para exigir envio real por Meta Cloud
WHATSAPP_PROVIDER=meta_cloud

# Versão da API Graph da Meta utilizada
WHATSAPP_CLOUD_API_VERSION=v21.0

# Token global de verificação de webhook (Verify Token)
# Deve ser uma string segura de sua escolha. Exemplo:
WHATSAPP_CLOUD_WEBHOOK_VERIFY_TOKEN=minha_assinatura_segura_de_webhook

# Chave secreta do aplicativo da Meta para validação de assinatura (X-Hub-Signature-256)
# Obrigatória em produção para evitar payloads falsificados/spoofed
WHATSAPP_CLOUD_APP_SECRET=minha_chave_secreta_do_app_meta
```

> [!WARNING]
> Nunca commite chaves de acesso ou tokens de verificação reais no repositório do Git. Utilize variáveis de ambiente ou configure os segredos diretamente pelo painel de controle.

---

## 2. Configurações da Integração no App (Painel de Gestão)

Cada empresa (Tenant) gerencia suas próprias credenciais de WhatsApp na aba **WhatsApp** nas configurações da plataforma. Os campos necessários são:

- **Phone number**: O número de telefone formatado do WhatsApp (Exemplo: `+5511999999999`).
- **Phone number ID**: O ID numérico do número de telefone fornecido pela Meta no painel de desenvolvedor.
- **Business account ID**: O ID da conta de negócios da Meta vinculada.
- **Access token**: O Token de Acesso temporário ou permanente do sistema (System User Access Token) gerado na conta empresarial do Facebook.
- **Webhook verify token**: O token de verificação específico desta empresa para validar o webhook. O sistema gera um automaticamente por padrão (formato aleatório), mas você pode definir o seu próprio.

---

## 3. URLs do Webhook (Endpoints)

A Meta envia requisições HTTP para a aplicação para validar a URL ou para entregar novas mensagens.

- **GET `/api/v1/webhooks/whatsapp/meta`**: Rota usada pelo Facebook para verificar o Webhook. O backend valida se o parâmetro `hub.verify_token` coincide com o `WHATSAPP_CLOUD_WEBHOOK_VERIFY_TOKEN` global ou com o `webhook_verify_token` configurado para a empresa correspondente, respondendo com o desafio (`hub.challenge`).
- **POST `/api/v1/webhooks/whatsapp/meta`**: Rota usada pela Meta para a ingestão de eventos e mensagens recebidas dos leads.

---

## 4. Segurança do Webhook (Assinatura X-Hub-Signature-256)

Para garantir que as requisições recebidas no webhook POST de fato vieram da Meta (e não de um atacante simulando um payload), o LEADSWHATS valida a assinatura digital enviada pelo Facebook:

- **Cabeçalho**: O Facebook envia o header `X-Hub-Signature-256: sha256=<assinatura_hex>`.
- **Cálculo**: O backend calcula o HMAC-SHA256 do corpo bruto da requisição usando a chave configurada em `WHATSAPP_CLOUD_APP_SECRET`.
- **Comparação**: Se a assinatura calculada não for idêntica à assinatura do cabeçalho, a requisição é rejeitada com status `403 Forbidden`.

### Comportamento por ambiente:
- **Production (`APP_ENV=production`)**: A assinatura é **estritamente obrigatória**. Se o cabeçalho estiver ausente ou a assinatura for inválida, a requisição é rejeitada.
- **Local/Testing**: Se `WHATSAPP_CLOUD_APP_SECRET` estiver vazio ou não configurado, a validação é pulada para fins de facilidade de testes (fallback). Se estiver configurado, a assinatura é validada normalmente.

---

## 5. Passo a Passo de Cadastro do Webhook na Meta

1. **Expor o Ambiente Local**: Se estiver rodando o projeto localmente (WSL), o servidor da Meta não conseguirá acessar `localhost`. Exponha a porta `8000` na internet usando `ngrok`:
   ```bash
   ngrok http 8000
   ```
   Copie a URL HTTPS gerada pelo ngrok (exemplo: `https://abcd-123.ngrok-free.app`).

2. **Registrar no Painel de Desenvolvedor da Meta**:
   - Vá em [developers.facebook.com](https://developers.facebook.com/) e acesse seu aplicativo.
   - Adicione o produto **WhatsApp** (se ainda não tiver feito).
   - No menu lateral do WhatsApp, clique em **Configurações** (ou **Webhook**).
   - Clique em **Editar** nas configurações de Webhook.
   - **URL de Retorno (Callback URL)**: Insira a URL exposta seguida do caminho `/api/v1/webhooks/whatsapp/meta`.
     * Exemplo: `https://abcd-123.ngrok-free.app/api/v1/webhooks/whatsapp/meta`
   - **Token de Verificação (Verify Token)**: Insira o token configurado na variável `WHATSAPP_CLOUD_WEBHOOK_VERIFY_TOKEN` (ou o verify token específico da empresa obtido no painel de configurações).
   - Clique em **Verificar e Salvar**.

3. **Assinar os Campos de Eventos**:
   - Após a verificação da URL, na mesma tela da Meta, clique em **Assinar** (Subscribe) no evento **messages**. Isso garante que novas mensagens enviadas pelos clientes cheguem ao nosso webhook.

---

## 6. Como Testar

### A. Testar a Verificação do Webhook (GET)
Você pode simular a requisição de verificação enviada pela Meta usando `curl` localmente:
```bash
curl -X GET "http://localhost:8000/api/v1/webhooks/whatsapp/meta?hub.mode=subscribe&hub.verify_token=<SEU_VERIFY_TOKEN>&hub.challenge=12345"
```
**Resposta Esperada**: Deve retornar apenas `12345` com status HTTP `200`.

### B. Testar a Ingestão Inbound Real (POST)
Envie uma mensagem real a partir de um número de WhatsApp pessoal de teste para o número empresarial conectado. 
- Verifique os logs do backend se necessário:
  ```bash
  docker compose logs -f backend
  ```
- O lead e a conversa correspondente devem ser criados na Inbox automaticamente.

### C. Testar o Envio pela Inbox (Outbound)
1. Acesse o painel do LEADSWHATS como Gestor ou SDR.
2. Abra a tela de **Inbox / Atendimento**.
3. Selecione a conversa iniciada pelo lead de teste.
4. Digite uma mensagem no campo de texto e clique em **Enviar**.
5. A mensagem deverá aparecer no celular do lead em poucos segundos, com a tag do provedor `meta_cloud`.

---

## 7. Limitações e Regras de Negócio

> [!IMPORTANT]
> **Privacidade dos Tokens**: Jamais salve tokens de acesso de produção em arquivos ou repositórios Git públicos. Garanta que as variáveis de ambiente `.env` fiquem restritas ao ambiente do servidor. No banco de dados do LEADSWHATS, os tokens de acesso salvos pelas empresas são armazenados de forma criptografada (`access_token_encrypted`).

- **Apenas Texto**: O MVP atual suporta apenas mensagens em formato de texto. Mídias (como fotos, vídeos e PDFs recebidos) serão ignorados no fluxo do webhook.
- **Sem Envio de Mídia**: O operador não consegue enviar imagens, áudios ou documentos a partir do painel.
- **Sem Templates**: O envio de mensagens de modelo (Templates WhatsApp) para iniciar novas conversas ativas sem interação prévia do cliente não está disponível.
- **Validação de Assinatura (X-Hub-Signature-256)**: Implementada. Em produção, a validação é estritamente obrigatória. Em desenvolvimento/testes, ela é validada apenas se a chave `WHATSAPP_CLOUD_APP_SECRET` estiver preenchida.
- **Janela de Atendimento (Service Window)**: O operador só pode enviar respostas caso a janela de atendimento de 24 horas esteja aberta (ou seja, o lead deve ter enviado uma mensagem nas últimas 24 horas). O frontend e o backend bloqueiam o envio fora desta janela.
