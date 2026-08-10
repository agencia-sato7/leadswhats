from enum import StrEnum
from typing import Annotated, Any

from pydantic import BaseModel, ConfigDict, Field, StringConstraints, field_validator


NonEmptyText = Annotated[str, StringConstraints(strip_whitespace=True, min_length=1)]
Score = Annotated[int, Field(ge=0, le=100)]
CriterionScore = Annotated[float, Field(ge=0, le=100)]
Confidence = Annotated[float, Field(ge=0, le=1)]


class StrictModel(BaseModel):
    model_config = ConfigDict(extra="forbid")


class Direction(StrEnum):
    INBOUND = "inbound"
    OUTBOUND = "outbound"


class LeadMetadata(StrictModel):
    id: int = Field(gt=0)
    name: str | None = None
    phone: str | None = None
    source: str | None = None
    creative_id: str | None = None
    creative_url: str | None = None
    campaign_name: str | None = None
    metadata: dict[str, Any] = Field(default_factory=dict)


class TranscriptMessage(StrictModel):
    id: int = Field(gt=0)
    direction: Direction
    channel: NonEmptyText
    body: str | None = None
    audio_transcript: str | None = None
    sent_at: NonEmptyText

    @field_validator("body", "audio_transcript")
    @classmethod
    def empty_text_becomes_none(cls, value: str | None) -> str | None:
        if value is None:
            return None
        normalized = value.strip()
        return normalized or None


class KanbanColumn(StrictModel):
    id: int = Field(gt=0)
    name: NonEmptyText
    rule_prompt: str | None = None


class ConversationAnalysisRequest(StrictModel):
    conversation_id: int = Field(gt=0)
    lead: LeadMetadata
    transcript: list[TranscriptMessage] = Field(min_length=1)
    current_kanban_column_id: int | None = Field(default=None, gt=0)
    kanban_columns: list[KanbanColumn] = Field(default_factory=list)


class CommercialData(StrictModel):
    need: str | None
    budget: str | None
    timeline: str | None
    decision_makers: list[str]
    next_step: str | None
    competitors: list[str]
    other_facts: list[str]


class CriteriaScores(StrictModel):
    discovery: CriterionScore
    clarity: CriterionScore
    empathy: CriterionScore
    objection_handling: CriterionScore


class ProviderConversationAnalysis(StrictModel):
    score: Score
    summary: NonEmptyText
    intent: str | None
    objections: list[str]
    positive_points: list[str]
    errors: list[str]
    improvement_suggestion: NonEmptyText
    commercial_data: CommercialData
    criteria_scores: CriteriaScores
    recommended_kanban_column_id: int | None = Field(gt=0)
    classification_reason: NonEmptyText
    confidence: Confidence


class ConversationAnalysisResponse(ProviderConversationAnalysis):
    prompt_version: NonEmptyText
    model_provider: NonEmptyText
    model_name: NonEmptyText


class HealthResponse(StrictModel):
    status: str
    provider: str
    configured: bool
    model: str | None


class ErrorDetail(StrictModel):
    code: NonEmptyText
    message: NonEmptyText


class ErrorResponse(StrictModel):
    detail: ErrorDetail
