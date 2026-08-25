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
- `POST /v1/analyze/campaign/evidence-batch`
- `POST /v1/analyze/campaign/consolidate`

O endpoint de análise usa Structured Outputs e valida tanto a entrada quanto a resposta com Pydantic. Falhas do provider retornam `502`; configuração ausente retorna `503`.

Os endpoints de campanha reutilizam a rubrica de qualidade da conversa: o primeiro pontua lotes de evidencias lead/dia e o segundo produz a sintese executiva mantendo volume e qualidade como eixos separados.

## Testes

```bash
pytest -q
```

Os testes substituem o provider por stubs e não realizam chamadas externas.
