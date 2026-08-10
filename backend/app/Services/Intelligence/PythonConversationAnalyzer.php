<?php

namespace App\Services\Intelligence;

use App\Contracts\Intelligence\ConversationAnalyzer;
use App\Data\Intelligence\ConversationAnalysisInput;
use App\Data\Intelligence\ConversationAnalysisResult;
use App\Exceptions\ConversationAnalyzerUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PythonConversationAnalyzer implements ConversationAnalyzer
{
    public function analyze(ConversationAnalysisInput $input): ConversationAnalysisResult
    {
        $serviceUrl = rtrim((string) config('intelligence.service_url'), '/');
        if ($serviceUrl === '') {
            throw new ConversationAnalyzerUnavailableException('AI_SERVICE_URL não foi configurada.');
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(max((int) config('intelligence.connect_timeout_seconds', 5), 1))
                ->timeout(max((int) config('intelligence.timeout_seconds', 90), 1))
                ->post($serviceUrl.'/v1/analyze/conversation', [
                    'conversation_id' => $input->conversationId,
                    'lead' => $input->leadMetadata,
                    'transcript' => $input->messages,
                    'current_kanban_column_id' => $input->currentKanbanColumnId,
                    'kanban_columns' => $input->kanbanColumns,
                ]);
        } catch (ConnectionException $exception) {
            throw new ConversationAnalyzerUnavailableException(
                'O microserviço de IA não está disponível no momento.',
                previous: $exception,
            );
        }

        if (!$response->successful()) {
            $message = $response->json('detail.message');
            throw new ConversationAnalyzerUnavailableException(
                is_string($message) && trim($message) !== ''
                    ? $message
                    : 'O microserviço de IA não conseguiu concluir a análise.',
            );
        }

        $payload = $response->json();
        if (!is_array($payload)) {
            throw new ConversationAnalyzerUnavailableException('O microserviço de IA retornou uma resposta inválida.');
        }

        $validator = Validator::make($payload, [
            'score' => ['required', 'integer', 'between:0,100'],
            'summary' => ['required', 'string', 'min:1'],
            'intent' => ['present', 'nullable', 'string'],
            'objections' => ['present', 'array'],
            'objections.*' => ['string'],
            'positive_points' => ['present', 'array'],
            'positive_points.*' => ['string'],
            'errors' => ['present', 'array'],
            'errors.*' => ['string'],
            'improvement_suggestion' => ['required', 'string', 'min:1'],
            'commercial_data' => ['present', 'array'],
            'criteria_scores' => ['present', 'array'],
            'criteria_scores.*' => ['numeric', 'between:0,100'],
            'recommended_kanban_column_id' => ['present', 'nullable', 'integer', 'min:1'],
            'classification_reason' => ['required', 'string', 'min:1'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'prompt_version' => ['required', 'string', 'min:1'],
            'model_provider' => ['required', 'string', 'min:1'],
            'model_name' => ['required', 'string', 'min:1'],
        ]);

        try {
            $validated = $validator->validate();
        } catch (ValidationException $exception) {
            throw new ConversationAnalyzerUnavailableException(
                'O microserviço de IA retornou uma resposta incompatível com o contrato.',
                previous: $exception,
            );
        }

        return new ConversationAnalysisResult(
            score: (int) $validated['score'],
            summary: $validated['summary'],
            intent: $validated['intent'],
            objections: $validated['objections'],
            positivePoints: $validated['positive_points'],
            errors: $validated['errors'],
            improvementSuggestion: $validated['improvement_suggestion'],
            commercialData: $validated['commercial_data'],
            criteriaScores: $validated['criteria_scores'],
            recommendedKanbanColumnId: isset($validated['recommended_kanban_column_id'])
                ? (int) $validated['recommended_kanban_column_id']
                : null,
            classificationReason: $validated['classification_reason'],
            confidence: (float) $validated['confidence'],
            promptVersion: $validated['prompt_version'],
            modelProvider: $validated['model_provider'],
            modelName: $validated['model_name'],
        );
    }
}
