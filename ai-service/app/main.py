from functools import lru_cache

from fastapi import Depends, FastAPI, Request, status
from fastapi.responses import JSONResponse

from app.config import Settings, get_settings
from app.models import (
    CampaignConsolidationRequest,
    CampaignConsolidationResponse,
    CampaignEvidenceBatchRequest,
    CampaignEvidenceBatchResponse,
    CampaignEvidenceResult,
    ConversationAnalysisRequest,
    ConversationAnalysisResponse,
    ErrorDetail,
    ErrorResponse,
    HealthResponse,
)
from app.prompts import (
    CAMPAIGN_CONSOLIDATION_PROMPT_VERSION,
    CAMPAIGN_EVIDENCE_PROMPT_VERSION,
    PROMPT_VERSION,
)
from app.provider import (
    ConversationAnalysisProvider,
    OpenAIConversationAnalysisProvider,
    ProviderConfigurationError,
    ProviderError,
)

app = FastAPI(title="LEADSWHATS AI Service", version="1.0.0")


@lru_cache
def get_analysis_provider() -> ConversationAnalysisProvider:
    return OpenAIConversationAnalysisProvider(get_settings())


def error_response(status_code: int, code: str, message: str) -> JSONResponse:
    payload = ErrorResponse(detail=ErrorDetail(code=code, message=message))
    return JSONResponse(status_code=status_code, content=payload.model_dump(mode="json"))


@app.exception_handler(ProviderConfigurationError)
async def provider_configuration_error_handler(
    _request: Request,
    exception: ProviderConfigurationError,
) -> JSONResponse:
    return error_response(
        status.HTTP_503_SERVICE_UNAVAILABLE,
        "provider_not_configured",
        str(exception),
    )


@app.exception_handler(ProviderError)
async def provider_error_handler(_request: Request, _exception: ProviderError) -> JSONResponse:
    return error_response(
        status.HTTP_502_BAD_GATEWAY,
        "provider_error",
        "O provider de IA não conseguiu concluir a análise.",
    )


@app.get("/health", response_model=HealthResponse)
def health(settings: Settings = Depends(get_settings)) -> HealthResponse:
    configured = bool(settings.openai_api_key.strip() and settings.openai_model.strip())
    return HealthResponse(
        status="ok",
        provider="openai",
        configured=configured,
        model=settings.openai_model or None,
    )


@app.post(
    "/v1/analyze/conversation",
    response_model=ConversationAnalysisResponse,
    responses={
        502: {"model": ErrorResponse},
        503: {"model": ErrorResponse},
    },
)
def analyze_conversation(
    payload: ConversationAnalysisRequest,
    provider: ConversationAnalysisProvider = Depends(get_analysis_provider),
    settings: Settings = Depends(get_settings),
) -> ConversationAnalysisResponse:
    analysis = provider.analyze(payload)
    allowed_column_ids = {column.id for column in payload.kanban_columns}

    if (
        analysis.recommended_kanban_column_id is not None
        and analysis.recommended_kanban_column_id not in allowed_column_ids
    ):
        raise ProviderError("O provider recomendou uma coluna inválida.")

    return ConversationAnalysisResponse(
        **analysis.model_dump(),
        prompt_version=PROMPT_VERSION,
        model_provider="openai",
        model_name=settings.openai_model,
    )


@app.post(
    "/v1/analyze/campaign/evidence-batch",
    response_model=CampaignEvidenceBatchResponse,
    responses={502: {"model": ErrorResponse}, 503: {"model": ErrorResponse}},
)
def analyze_campaign_evidence(
    payload: CampaignEvidenceBatchRequest,
    provider: ConversationAnalysisProvider = Depends(get_analysis_provider),
    settings: Settings = Depends(get_settings),
) -> CampaignEvidenceBatchResponse:
    analysis = provider.analyze_campaign_evidence(payload)
    expected_keys = [item.key for item in payload.evidences]
    returned_keys = [item.key for item in analysis.evidences]
    if returned_keys != expected_keys:
        raise ProviderError("O provider alterou a ordem ou as chaves das evidências.")

    return CampaignEvidenceBatchResponse(
        evidences=[
            CampaignEvidenceResult(
                **item.model_dump(),
                prompt_version=CAMPAIGN_EVIDENCE_PROMPT_VERSION,
                model_provider="openai",
                model_name=settings.openai_model,
            )
            for item in analysis.evidences
        ]
    )


@app.post(
    "/v1/analyze/campaign/consolidate",
    response_model=CampaignConsolidationResponse,
    responses={502: {"model": ErrorResponse}, 503: {"model": ErrorResponse}},
)
def consolidate_campaign(
    payload: CampaignConsolidationRequest,
    provider: ConversationAnalysisProvider = Depends(get_analysis_provider),
    settings: Settings = Depends(get_settings),
) -> CampaignConsolidationResponse:
    result = provider.consolidate_campaign(payload)
    return CampaignConsolidationResponse(
        **result.model_dump(),
        prompt_version=CAMPAIGN_CONSOLIDATION_PROMPT_VERSION,
        model_provider="openai",
        model_name=settings.openai_model,
    )
