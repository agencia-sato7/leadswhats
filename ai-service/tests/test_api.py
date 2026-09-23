from collections.abc import Iterator
from types import SimpleNamespace

import pytest
from fastapi.testclient import TestClient

from app.config import Settings, get_settings
from app.main import app, get_analysis_provider
from app.models import (
    CampaignConsolidationRequest,
    CampaignEvidenceBatchRequest,
    ConversationAnalysisRequest,
    DailyReportRequest,
    ProviderCampaignConsolidation,
    ProviderCampaignEvidence,
    ProviderCampaignEvidenceBatch,
    ProviderConversationAnalysis,
    ProviderDailyReport,
)
from app.provider import OpenAIConversationAnalysisProvider, ProviderError
from app.prompts import (
    DAILY_REPORT_PROMPT,
    DAILY_REPORT_PROMPT_VERSION,
    PROMPT_VERSION,
    SYSTEM_PROMPT,
)


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


class CampaignStubProvider:
    def analyze_campaign_evidence(
        self, payload: CampaignEvidenceBatchRequest
    ) -> ProviderCampaignEvidenceBatch:
        return ProviderCampaignEvidenceBatch(
            evidences=[
                ProviderCampaignEvidence(
                    key=item.key,
                    score=76,
                    criteria_scores={
                        "discovery": 72,
                        "clarity": 78,
                        "empathy": 80,
                        "objection_handling": 70,
                    },
                    summary="Atendimento adequado no recorte.",
                    positive_points=["Continuidade do contato"],
                    errors=[],
                    improvement_suggestion="Confirmar o próximo passo.",
                )
                for item in payload.evidences
            ]
        )

    def consolidate_campaign(
        self, _payload: CampaignConsolidationRequest
    ) -> ProviderCampaignConsolidation:
        return ProviderCampaignConsolidation(
            executive_summary="O volume cresceu e o atendimento foi adequado.",
            overall_verdict="good",
            volume_summary="Foram recebidos mais leads que no período anterior.",
            service_summary="A qualidade média ficou na faixa boa.",
            new_leads_summary="Os novos leads receberam condução adequada.",
            rescued_leads_summary="Os resgates tiveram retomada objetiva.",
            priorities=["Confirmar próximos passos"],
        )


class DailyReportStubProvider:
    def __init__(self) -> None:
        self.received: DailyReportRequest | None = None

    def generate_daily_report(self, payload: DailyReportRequest) -> ProviderDailyReport:
        self.received = payload
        return daily_report_result()


class DailyReportFailingProvider:
    def generate_daily_report(self, _payload: DailyReportRequest) -> ProviderDailyReport:
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


def campaign_evidence_request() -> dict:
    return {
        "evidences": [
            {
                "key": "evidence-hash-1",
                "analysis_date": "2026-08-10",
                "lead": {"id": 55, "name": "Ana"},
                "owner": {"id": 9, "name": "João"},
                "stage_name": "Em Atendimento",
                "messages": [
                    {
                        "id": 1,
                        "direction": "inbound",
                        "channel": "text",
                        "body": "Quero agendar.",
                        "audio_transcript": None,
                        "sent_at": "2026-08-10T10:00:00-03:00",
                        "is_rescue": False,
                        "context_only": False,
                    }
                ],
                "metrics": {
                    "message_count": 1,
                    "inbound_count": 1,
                    "outbound_count": 0,
                    "rescue_attempts": 0,
                },
            }
        ]
    }


def test_campaign_evidence_batch_uses_structured_contract() -> None:
    app.dependency_overrides[get_analysis_provider] = lambda: CampaignStubProvider()
    response = TestClient(app).post(
        "/v1/analyze/campaign/evidence-batch", json=campaign_evidence_request()
    )
    assert response.status_code == 200
    assert response.json()["evidences"][0]["key"] == "evidence-hash-1"
    assert response.json()["evidences"][0]["score"] == 76
    assert response.json()["evidences"][0]["prompt_version"] == "campaign-evidence-v1"


def test_campaign_consolidation_keeps_volume_and_quality_separate() -> None:
    app.dependency_overrides[get_analysis_provider] = lambda: CampaignStubProvider()
    response = TestClient(app).post(
        "/v1/analyze/campaign/consolidate",
        json={
            "range": {"start_date": "2026-08-01", "end_date": "2026-08-07"},
            "metrics": {"volume": {"current_new_leads": 10, "previous_new_leads": 8}},
            "quality": {"score": 76},
            "cohorts": {"new": {"score": 75}, "rescued": {"score": 80}},
            "team": [],
            "base_report": None,
            "evidence_summaries": [],
        },
    )
    assert response.status_code == 200
    assert response.json()["overall_verdict"] == "good"
    assert response.json()["prompt_version"] == "campaign-consolidation-v1"


def daily_report_request() -> dict:
    return {
        "company": {"id": 7, "name": "Clínica Teste", "slug": "clinica-teste"},
        "report_date": "2026-09-23",
        "metrics": {
            "new_leads_today": 6,
            "previous_new_leads": 4,
            "avg_first_response_seconds": 395,
            "effectiveness_percentage": 100,
        },
        "quality": {
            "analyzed_conversations": 4,
            "average_score": 75.5,
            "low_quality_conversations": 2,
            "criteria_averages": {
                "discovery": 74.3,
                "clarity": 70.3,
                "empathy": 72.0,
                "objection_handling": 65.3,
            },
        },
        "team": [{"name": "Marina Costa", "average_score": 56.7}],
    }


def daily_report_result() -> ProviderDailyReport:
    return ProviderDailyReport.model_validate(
        {
            "executive_summary": "O dia registrou 6 leads novos e média de qualidade 75.5.",
            "overall_verdict": "good",
            "volume_summary": "O volume de novos leads cresceu em relação ao dia anterior.",
            "quality_summary": "A qualidade foi boa, com objeções como critério mais fraco.",
            "opportunities": ["Reforçar tratamento de objeções."],
            "priorities": ["Acompanhar os leads sem resposta do dia."],
        }
    )


def test_daily_report_returns_validated_structured_response_without_real_call() -> None:
    provider = DailyReportStubProvider()
    app.dependency_overrides[get_analysis_provider] = lambda: provider

    response = TestClient(app).post("/v1/analyze/daily-report", json=daily_report_request())

    assert response.status_code == 200
    assert response.json()["overall_verdict"] == "good"
    assert response.json()["prompt_version"] == "daily-report-v1"
    assert response.json()["model_provider"] == "openai"
    assert response.json()["model_name"] == "test-model"
    assert provider.received is not None
    assert provider.received.report_date == "2026-09-23"
    assert provider.received.quality["average_score"] == 75.5


def test_daily_report_rejects_request_without_metrics_or_quality() -> None:
    without_metrics = {
        key: value for key, value in daily_report_request().items() if key != "metrics"
    }

    assert (
        TestClient(app).post("/v1/analyze/daily-report", json=without_metrics).status_code == 422
    )

    without_company = {
        key: value for key, value in daily_report_request().items() if key != "company"
    }

    assert (
        TestClient(app).post("/v1/analyze/daily-report", json=without_company).status_code == 422
    )


def test_daily_report_provider_failure_returns_controlled_error() -> None:
    app.dependency_overrides[get_analysis_provider] = lambda: DailyReportFailingProvider()

    response = TestClient(app).post("/v1/analyze/daily-report", json=daily_report_request())

    assert response.status_code == 502
    assert response.json()["detail"]["code"] == "provider_error"
    assert "detalhe interno" not in response.text


def test_daily_report_prompt_keeps_volume_and_quality_guardrails() -> None:
    normalized_prompt = " ".join(DAILY_REPORT_PROMPT.split())
    required_guidance = [
        "Não recalcule nem invente números",
        "volume compara novos leads com o dia anterior equivalente",
        "sem criar média matemática entre os eixos",
        "se não houver conversas analisadas no dia, registre isso claramente",
    ]

    for guidance in required_guidance:
        assert guidance in normalized_prompt

    assert DAILY_REPORT_PROMPT_VERSION == "daily-report-v1"


def test_openai_provider_uses_daily_report_prompt_without_real_call(monkeypatch) -> None:
    captured: dict[str, object] = {}

    class FakeResponses:
        def parse(self, **kwargs):
            captured.update(kwargs)
            return SimpleNamespace(output_parsed=daily_report_result())

    class FakeOpenAI:
        def __init__(self, **kwargs):
            captured["client_options"] = kwargs
            self.responses = FakeResponses()

    monkeypatch.setattr("app.provider.OpenAI", FakeOpenAI)
    provider = OpenAIConversationAnalysisProvider(
        Settings(OPENAI_API_KEY="test-key", OPENAI_MODEL="test-model")
    )

    result = provider.generate_daily_report(
        DailyReportRequest.model_validate(daily_report_request())
    )

    assert result == daily_report_result()
    assert captured["model"] == "test-model"
    assert captured["text_format"] is ProviderDailyReport
    assert captured["store"] is False
    assert captured["input"][0] == {"role": "system", "content": DAILY_REPORT_PROMPT}
