<?php

namespace Tests\Unit\Intelligence;

use App\Contracts\Intelligence\ConversationAnalyzer;
use App\Data\Intelligence\ConversationAnalysisInput;
use App\Exceptions\ConversationAnalyzerUnavailableException;
use App\Services\Intelligence\PythonConversationAnalyzer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            $payload = json_decode($request->body());

            return $request->url() === 'http://ai-service.test:8001/v1/analyze/conversation'
                && $request['conversation_id'] === 101
                && $request['lead']['source'] === 'instagram'
                && $request['transcript'][0]['direction'] === 'inbound'
                && $request['kanban_columns'][1]['rule_prompt'] === 'Lead pronto para proposta'
                && $payload->lead->metadata instanceof \stdClass;
        });
    }

    public function test_associative_lead_metadata_and_media_messages_are_preserved(): void
    {
        Http::fake([
            'http://ai-service.test:8001/v1/analyze/conversation' => Http::response($this->validResponse(), 200),
        ]);

        $input = $this->input();
        $input = new ConversationAnalysisInput(
            companyId: $input->companyId,
            conversationId: $input->conversationId,
            leadId: $input->leadId,
            leadMetadata: [...$input->leadMetadata, 'metadata' => ['campaign' => 'summer']],
            transcript: $input->transcript,
            messages: [
                ...$input->messages,
                [
                    'id' => 2,
                    'direction' => 'inbound',
                    'channel' => 'image',
                    'body' => null,
                    'audio_transcript' => null,
                    'sent_at' => '2026-08-09T10:01:00-03:00',
                ],
                [
                    'id' => 3,
                    'direction' => 'inbound',
                    'channel' => 'audio',
                    'body' => '',
                    'audio_transcript' => null,
                    'sent_at' => '2026-08-09T10:02:00-03:00',
                ],
            ],
            currentKanbanColumnId: $input->currentKanbanColumnId,
            kanbanColumns: $input->kanbanColumns,
        );

        app(ConversationAnalyzer::class)->analyze($input);

        Http::assertSent(function (Request $request): bool {
            return $request['lead']['metadata'] === ['campaign' => 'summer']
                && $request['transcript'][1]['channel'] === 'image'
                && $request['transcript'][1]['body'] === null
                && $request['transcript'][2]['channel'] === 'audio'
                && $request['transcript'][2]['body'] === '';
        });
    }

    public function test_validation_error_is_logged_with_context_and_returned_safely(): void
    {
        Log::spy();
        Http::fake([
            'http://ai-service.test:8001/v1/analyze/conversation' => Http::response([
                'detail' => [[
                    'type' => 'dict_type',
                    'loc' => ['body', 'lead', 'metadata'],
                    'msg' => 'Input should be a valid dictionary',
                    'input' => ['sensitive' => 'must not be logged'],
                ]],
            ], 422),
        ]);

        try {
            app(ConversationAnalyzer::class)->analyze($this->input());
            $this->fail('Expected analyzer exception was not thrown.');
        } catch (ConversationAnalyzerUnavailableException $exception) {
            $this->assertSame('O microserviço de IA não conseguiu concluir a análise.', $exception->getMessage());
        }

        Log::shouldHaveReceived('warning')->once()->with(
            'Conversation analyzer rejected the request contract.',
            \Mockery::on(fn (array $context): bool => $context['company_id'] === 1
                && $context['conversation_id'] === 101
                && $context['status'] === 422
                && $context['validation_errors'][0]['loc'] === ['body', 'lead', 'metadata']
                && !array_key_exists('input', $context['validation_errors'][0])),
        );
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
