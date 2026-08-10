# LEADSWHATS AI Service

Microserviço FastAPI responsável pela integração real de Conversation Intelligence com a OpenAI. O Laravel envia apenas o contexto da conversa e nunca recebe `OPENAI_API_KEY`.

## Variáveis

```env
OPENAI_API_KEY=
OPENAI_MODEL=gpt-4o-mini
OPENAI_TIMEOUT_SECONDS=60
```

## Endpoints

- `GET /health`
- `POST /v1/analyze/conversation`

O endpoint de análise usa Structured Outputs e valida tanto a entrada quanto a resposta com Pydantic. Falhas do provider retornam `502`; configuração ausente retorna `503`.

## Testes

```bash
pytest -q
```

Os testes substituem o provider por stubs e não realizam chamadas externas.
