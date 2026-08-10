<?php

namespace Tests\Unit\Intelligence;

use App\Contracts\Intelligence\ConversationAnalyzer;
use App\Data\Intelligence\ConversationAnalysisInput;
use App\Exceptions\ConversationAnalyzerUnavailableException;
use App\Services\Intelligence\PythonConversationAnalyzer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PythonConversationAnalyzerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('intelligence.analyzer', 'python');
        config()->set('intelligence.service_url', 'http://ai-service.test:8001');
        config()->set('intelligence.timeout_seconds', 10);
        config()->set('intelligence.connect_timeout_seconds', 2);
        Http::preventStrayRequests();
    }

    public function test_python_analyzer_is_selected_explicitly_and_maps_the_contract(): void
    {
        Http::fake([
            'http://ai-service.test:8001/v1/analyze/conversation' => Http::response($this->validResponse(), 200),
        ]);

        $analyzer = app(ConversationAnalyzer::class);
        $this->assertInstanceOf(PythonConversationAnalyzer::class, $analyzer);

        $result = $analyzer->analyze($this->input());

        $this->assertSame(87, $result->score);
        $this->assertSame(['discovery' => 90, 'clarity' => 85], $result->criteriaScores);
        $this->assertSame(20, $result->recommendedKanbanColumnId);
        $this->assertSame('openai', $result->modelProvider);
        $this->assertSame('test-model', $result->modelName);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://ai-service.test:8001/v1/analyze/conversation'
                && $request['conversation_id'] === 101
                && $request['lead']['source'] === 'instagram'
                && $request['transcript'][0]['direction'] === 'inbound'
                && $request['kanban_columns'][1]['rule_prompt'] === 'Lead pronto para proposta';
        });
    }

    public function test_provider_error_is_controlled_and_never_falls_back_to_fake(): void
    {
        Http::fake([
            'http://ai-service.test:8001/v1/analyze/conversation' => Http::response([
                'detail' => [
                    'code' => 'provider_error',
                    'message' => 'O provider de IA não conseguiu concluir a análise.',
                ],
            ], 502),
        ]);

        $this->expectException(ConversationAnalyzerUnavailableException::class);
        $this->expectExceptionMessage('O provider de IA não conseguiu concluir a análise.');

        app(ConversationAnalyzer::class)->analyze($this->input());
    }

    public function test_invalid_microservice_response_is_rejected(): void
    {
        Http::fake([
            'http://ai-service.test:8001/v1/analyze/conversation' => Http::response([
                ...$this->validResponse(),
                'score' => 101,
            ], 200),
        ]);

        $this->expectException(ConversationAnalyzerUnavailableException::class);
        $this->expectExceptionMessage('resposta incompatível com o contrato');

        app(ConversationAnalyzer::class)->analyze($this->input());
    }

    private function input(): ConversationAnalysisInput
    {
        return new ConversationAnalysisInput(
            companyId: 1,
            conversationId: 101,
            leadId: 55,
            leadMetadata: [
                'id' => 55,
                'name' => 'Ana',
                'phone' => '+5511999999999',
                'source' => 'instagram',
                'creative_id' => null,
                'creative_url' => null,
                'campaign_name' => null,
                'metadata' => [],
            ],
            transcript: "[Cliente] Preciso de uma proposta.\n[Atendente] Vamos detalhar a necessidade.",
            messages: [
                [
                    'id' => 1,
                    'direction' => 'inbound',
                    'channel' => 'text',
                    'body' => 'Preciso de uma proposta.',
                    'audio_transcript' => null,
                    'sent_at' => '2026-08-09T10:00:00-03:00',
                ],
            ],
            currentKanbanColumnId: 10,
            kanbanColumns: [
                ['id' => 10, 'name' => 'Atendimento', 'rule_prompt' => null],
                ['id' => 20, 'name' => 'Proposta', 'rule_prompt' => 'Lead pronto para proposta'],
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validResponse(): array
    {
        return [
            'score' => 87,
            'summary' => 'Conversa com boa descoberta e próximo passo claro.',
            'intent' => 'Solicitar proposta',
            'objections' => [],
            'positive_points' => ['Boa pergunta de descoberta'],
            'errors' => [],
            'improvement_suggestion' => 'Confirmar prazo e decisores.',
            'commercial_data' => [
                'need' => 'Receber uma proposta',
                'budget' => null,
                'timeline' => null,
                'decision_makers' => [],
                'next_step' => 'Detalhar necessidade',
                'competitors' => [],
                'other_facts' => [],
            ],
            'criteria_scores' => ['discovery' => 90, 'clarity' => 85],
            'recommended_kanban_column_id' => 20,
            'classification_reason' => 'O lead pediu uma proposta.',
            'confidence' => 0.91,
            'prompt_version' => 'conversation-quality-v1',
            'model_provider' => 'openai',
            'model_name' => 'test-model',
        ];
    }
}
