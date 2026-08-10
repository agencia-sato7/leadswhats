import json
from typing import Protocol

from openai import OpenAI, OpenAIError
from pydantic import ValidationError

from app.config import Settings
from app.models import ConversationAnalysisRequest, ProviderConversationAnalysis
from app.prompts import SYSTEM_PROMPT


class ProviderError(RuntimeError):
    pass


class ProviderConfigurationError(ProviderError):
    pass


class ConversationAnalysisProvider(Protocol):
    def analyze(self, payload: ConversationAnalysisRequest) -> ProviderConversationAnalysis:
        ...


class OpenAIConversationAnalysisProvider:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    def analyze(self, payload: ConversationAnalysisRequest) -> ProviderConversationAnalysis:
        if not self.settings.openai_api_key.strip():
            raise ProviderConfigurationError("OPENAI_API_KEY não foi configurada.")
        if not self.settings.openai_model.strip():
            raise ProviderConfigurationError("OPENAI_MODEL não foi configurado.")

        try:
            client = OpenAI(
                api_key=self.settings.openai_api_key,
                timeout=self.settings.openai_timeout_seconds,
                max_retries=1,
            )
            response = client.responses.parse(
                model=self.settings.openai_model,
                input=[
                    {"role": "system", "content": SYSTEM_PROMPT},
                    {
                        "role": "user",
                        "content": json.dumps(
                            payload.model_dump(mode="json"),
                            ensure_ascii=False,
                            separators=(",", ":"),
                        ),
                    },
                ],
                text_format=ProviderConversationAnalysis,
                store=False,
            )
        except (OpenAIError, ValidationError) as exception:
            raise ProviderError("O provider de IA não conseguiu concluir a análise.") from exception

        parsed = response.output_parsed
        if parsed is None:
            raise ProviderError("O provider de IA não retornou uma análise estruturada.")

        try:
            return ProviderConversationAnalysis.model_validate(parsed)
        except ValidationError as exception:
            raise ProviderError("O provider de IA retornou uma análise inválida.") from exception
