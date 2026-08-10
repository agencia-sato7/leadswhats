from collections.abc import Iterator
from types import SimpleNamespace

import pytest
from fastapi.testclient import TestClient

from app.config import Settings, get_settings
from app.main import app, get_analysis_provider
from app.models import ConversationAnalysisRequest, ProviderConversationAnalysis
from app.provider import OpenAIConversationAnalysisProvider, ProviderError
from app.prompts import PROMPT_VERSION, SYSTEM_PROMPT


VALID_REQUEST = {
    "conversation_id": 101,
    "lead": {
        "id": 55,
        "name": "Ana",
        "phone": "+5511999999999",
        "source": "instagram",
        "creative_id": None,
        "creative_url": None,
        "campaign_name": None,
        "metadata": {},
    },
    "transcript": [
        {
            "id": 1,
            "direction": "inbound",
            "channel": "text",
            "body": "Preciso organizar o atendimento da equipe.",
            "audio_transcript": None,
            "sent_at": "2026-08-09T10:00:00-03:00",
        },
        {
            "id": 2,
            "direction": "outbound",
            "channel": "text",
            "body": "Quantas pessoas atendem hoje?",
            "audio_transcript": None,
            "sent_at": "2026-08-09T10:01:00-03:00",
        },
    ],
    "current_kanban_column_id": 10,
    "kanban_columns": [
        {"id": 10, "name": "Atendimento", "rule_prompt": "Descoberta inicial"},
        {"id": 20, "name": "Proposta", "rule_prompt": "Lead pediu proposta"},
    ],
}


def valid_analysis(recommended_column_id: int | None = 10) -> ProviderConversationAnalysis:
    return ProviderConversationAnalysis.model_validate(
        {
            "score": 78,
            "summary": "A conversa iniciou uma descoberta objetiva.",
            "intent": "Organizar o atendimento",
            "objections": [],
            "positive_points": ["Pergunta de descoberta"],
            "errors": [],
            "improvement_suggestion": "Aprofundar volume e impacto operacional.",
            "commercial_data": {
                "need": "Organizar o atendimento da equipe",
                "budget": None,
                "timeline": None,
                "decision_makers": [],
                "next_step": None,
                "competitors": [],
                "other_facts": [],
            },
            "criteria_scores": {
                "discovery": 80,
                "clarity": 82,
                "empathy": 75,
                "objection_handling": 60,
            },
            "recommended_kanban_column_id": recommended_column_id,
            "classification_reason": "Ainda está em descoberta.",
            "confidence": 0.88,
        }
    )


class StubProvider:
    def __init__(self, result: ProviderConversationAnalysis) -> None:
        self.result = result
        self.received: ConversationAnalysisRequest | None = None

    def analyze(self, payload: ConversationAnalysisRequest) -> ProviderConversationAnalysis:
        self.received = payload
        return self.result


class FailingProvider:
    def analyze(self, _payload: ConversationAnalysisRequest) -> ProviderConversationAnalysis:
        raise ProviderError("detalhe interno que não deve vazar")


@pytest.fixture(autouse=True)
def clear_overrides() -> Iterator[None]:
    app.dependency_overrides[get_settings] = lambda: Settings(
        OPENAI_API_KEY="test-key",
        OPENAI_MODEL="test-model",
    )
    yield
    app.dependency_overrides.clear()


def test_health_does_not_call_provider() -> None:
    response = TestClient(app).get("/health")

    assert response.status_code == 200
    assert response.json() == {
        "status": "ok",
        "provider": "openai",
        "configured": True,
        "model": "test-model",
    }


def test_analyze_returns_validated_structured_response_without_real_call() -> None:
    provider = StubProvider(valid_analysis())
    app.dependency_overrides[get_analysis_provider] = lambda: provider

    response = TestClient(app).post("/v1/analyze/conversation", json=VALID_REQUEST)

    assert response.status_code == 200
    assert response.json()["score"] == 78
    assert response.json()["commercial_data"]["budget"] is None
    assert response.json()["recommended_kanban_column_id"] == 10
    assert response.json()["prompt_version"] == "conversation-quality-v2"
    assert response.json()["model_provider"] == "openai"
    assert response.json()["model_name"] == "test-model"
    assert provider.received is not None
    assert provider.received.transcript[0].direction.value == "inbound"


def test_request_validation_rejects_invalid_score_independently_of_openai() -> None:
    with pytest.raises(ValueError):
        ProviderConversationAnalysis.model_validate(
            {**valid_analysis().model_dump(), "score": 101}
        )

    invalid = {**VALID_REQUEST, "transcript": []}
    response = TestClient(app).post("/v1/analyze/conversation", json=invalid)
    assert response.status_code == 422


def test_recommended_column_must_be_supplied_or_null() -> None:
    app.dependency_overrides[get_analysis_provider] = lambda: StubProvider(valid_analysis(999))

    response = TestClient(app).post("/v1/analyze/conversation", json=VALID_REQUEST)

    assert response.status_code == 502
    assert response.json()["detail"]["code"] == "provider_error"


def test_provider_failure_returns_controlled_error_without_leaking_details() -> None:
    app.dependency_overrides[get_analysis_provider] = lambda: FailingProvider()

    response = TestClient(app).post("/v1/analyze/conversation", json=VALID_REQUEST)

    assert response.status_code == 502
    assert response.json() == {
        "detail": {
            "code": "provider_error",
            "message": "O provider de IA não conseguiu concluir a análise.",
        }
    }


def test_missing_provider_configuration_returns_controlled_error() -> None:
    settings = Settings(OPENAI_API_KEY="", OPENAI_MODEL="test-model")
    app.dependency_overrides[get_analysis_provider] = lambda: OpenAIConversationAnalysisProvider(
        settings
    )

    response = TestClient(app).post("/v1/analyze/conversation", json=VALID_REQUEST)

    assert response.status_code == 503
    assert response.json() == {
        "detail": {
            "code": "provider_not_configured",
            "message": "OPENAI_API_KEY não foi configurada.",
        }
    }


def test_refined_dental_prompt_has_rigorous_commercial_safeguards() -> None:
    normalized_prompt = " ".join(SYSTEM_PROMPT.split())
    required_guidance = [
        "Não produza diagnóstico clínico",
        "não determine a condição do paciente",
        "não recomende implante, prótese ou qualquer tratamento odontológico",
        "Não invente preços, faixas de preço, descontos, parcelamentos",
        "Não sugira que o atendente informe valores ausentes",
        "Saudações ou convite genérico para avaliação não contam como descoberta",
        "Cordialidade genérica",
        "construção de valor antes de preço/parcelamento",
        "vou pensar",
        "meu orçamento está apertado",
        "Para cada critério abaixo de 60",
        "errors só pode ser vazio quando todos os critérios forem pelo menos 60",
        "improvement_suggestion deve indicar comportamento comercial executável",
        "Uma conversa ruim pode continuar “Em Atendimento”",
    ]

    for guidance in required_guidance:
        assert guidance in normalized_prompt

    assert PROMPT_VERSION == "conversation-quality-v2"


def test_openai_provider_keeps_structured_outputs_without_real_call(monkeypatch) -> None:
    captured: dict[str, object] = {}

    class FakeResponses:
        def parse(self, **kwargs):
            captured.update(kwargs)
            return SimpleNamespace(output_parsed=valid_analysis())

    class FakeOpenAI:
        def __init__(self, **kwargs):
            captured["client_options"] = kwargs
            self.responses = FakeResponses()

    monkeypatch.setattr("app.provider.OpenAI", FakeOpenAI)
    provider = OpenAIConversationAnalysisProvider(
        Settings(OPENAI_API_KEY="test-key", OPENAI_MODEL="test-model")
    )

    result = provider.analyze(ConversationAnalysisRequest.model_validate(VALID_REQUEST))

    assert result == valid_analysis()
    assert captured["model"] == "test-model"
    assert captured["text_format"] is ProviderConversationAnalysis
    assert captured["store"] is False
    assert captured["input"][0] == {"role": "system", "content": SYSTEM_PROMPT}
