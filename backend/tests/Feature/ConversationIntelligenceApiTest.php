<?php

namespace Tests\Feature;

use App\Contracts\Intelligence\ConversationAnalyzer;
use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Conversation;
use App\Models\ConversationQualityScore;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\User;
use App\Services\Intelligence\FakeConversationAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class ConversationIntelligenceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Estes testes validam deliberadamente o analisador determinístico local.
        // O fake precisa ser opt-in mesmo quando o container Docker usa Python.
        config()->set('intelligence.analyzer', 'fake');
        Http::preventStrayRequests();
        $this->artisan('leadswhats:demo-bootstrap')->assertExitCode(0);
    }

    public function test_local_and_testing_use_the_fake_analyzer_and_demo_has_analyzed_and_pending_conversations(): void
    {
        $this->assertInstanceOf(FakeConversationAnalyzer::class, app(ConversationAnalyzer::class));

        $company = Company::query()->where('slug', 'empresa-demo')->firstOrFail();
        $this->assertSame(14, Conversation::query()->where('company_id', $company->id)->count());
        $this->assertSame(10, ConversationQualityScore::query()->where('company_id', $company->id)->count());

        $response = $this->withToken($this->loginToken('gestor@empresa.local'))
            ->getJson('/api/v1/intelligence/conversations')
            ->assertOk()
            ->assertJsonPath('meta.total', 14);

        $conversations = collect($response->json('data'));
        $this->assertCount(10, $conversations->where('analysis_status', 'analyzed'));
        $this->assertCount(4, $conversations->where('analysis_status', 'pending'));

        $this->withToken($this->loginToken('gestor@empresa.local'))
            ->getJson('/api/v1/intelligence/summary')
            ->assertOk()
            ->assertJsonPath('data.total_conversations', 14)
            ->assertJsonPath('data.analyzed_conversations', 10)
            ->assertJsonPath('data.pending_conversations', 4)
            ->assertJsonPath('data.total_snapshots', 10);
    }

    public function test_analyze_creates_a_structured_snapshot_without_moving_the_lead(): void
    {
        $conversation = Conversation::query()->whereHas('lead', fn ($query) => $query->where('name', 'Larissa Mendes'))->firstOrFail();
        $historyCountBefore = LeadStageHistory::query()->where('lead_id', $conversation->lead_id)->count();

        $response = $this->withToken($this->loginToken('gestor@empresa.local'))
            ->postJson("/api/v1/intelligence/conversations/{$conversation->id}/analyze")
            ->assertCreated()
            ->assertJsonPath('data.analysis_status', 'analyzed')
            ->assertJsonPath('data.latest_analysis.analysis_version', 1)
            ->assertJsonPath('data.latest_analysis.model_provider', 'fake')
            ->assertJsonStructure([
                'data' => [
                    'latest_analysis' => [
                        'score',
                        'summary',
                        'intent',
                        'objections',
                        'positive_points',
                        'errors',
                        'improvement_suggestion',
                        'commercial_data',
                        'criteria_scores',
                        'recommended_kanban_column_id',
                        'classification_reason',
                        'confidence',
                    ],
                ],
            ]);

        $score = (int) $response->json('data.latest_analysis.score');
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
        $this->assertNotNull($response->json('data.latest_analysis.recommended_kanban_column_id'));
        $this->assertSame($historyCountBefore, LeadStageHistory::query()->where('lead_id', $conversation->lead_id)->count());
        $this->assertDatabaseCount('conversation_quality_scores', 11);
    }

    public function test_reanalyze_preserves_previous_snapshot_and_snapshots_are_immutable(): void
    {
        $conversation = Conversation::query()->whereHas('lead', fn ($query) => $query->where('name', 'Larissa Mendes'))->firstOrFail();
        $token = $this->loginToken('gestor@empresa.local');

        $this->withToken($token)->postJson("/api/v1/intelligence/conversations/{$conversation->id}/analyze")->assertCreated();
        $this->withToken($token)->postJson("/api/v1/intelligence/conversations/{$conversation->id}/analyze")
            ->assertCreated()
            ->assertJsonPath('data.latest_analysis.analysis_version', 2)
            ->assertJsonCount(2, 'data.analysis_history');

        $versions = ConversationQualityScore::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('analysis_version')
            ->pluck('analysis_version')
            ->all();
        $this->assertSame([1, 2], $versions);

        $this->expectException(LogicException::class);
        $snapshot = ConversationQualityScore::query()->where('analysis_version', 1)->firstOrFail();
        $snapshot->update(['summary' => 'Tentativa de sobrescrever o histórico.']);
    }

    public function test_intelligence_is_manager_only_and_tenant_scoped(): void
    {
        $conversation = Conversation::query()->firstOrFail();

        $this->withToken($this->loginToken('sdr@empresa.local'))
            ->getJson('/api/v1/intelligence/conversations')
            ->assertForbidden();

        $otherCompany = Company::query()->create(['name' => 'Outra empresa', 'slug' => 'outra-empresa']);
        $otherManager = User::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Gestor externo',
            'email' => 'gestor@outra.local',
            'password' => '12345678',
            'role' => 'gestor',
            'active' => true,
        ]);

        $otherToken = $this->postJson('/api/v1/auth/login', [
            'email' => $otherManager->email,
            'password' => '12345678',
        ])->assertOk()->json('token');

        $this->withToken($otherToken)
            ->getJson("/api/v1/intelligence/conversations/{$conversation->id}")
            ->assertNotFound();

        $this->withToken($otherToken)
            ->postJson("/api/v1/intelligence/conversations/{$conversation->id}/analyze")
            ->assertNotFound();
    }

    public function test_ingestion_only_persists_and_does_not_analyze_or_move_by_rule_prompt(): void
    {
        $company = Company::query()->where('slug', 'empresa-demo')->firstOrFail();
        $webhookToken = CompanyBusinessSetting::query()->where('company_id', $company->id)->value('webhook_token');

        $this->withHeader('X-Webhook-Token', (string) $webhookToken)
            ->postJson('/api/v1/webhooks/whatsapp', [
                'company_slug' => $company->slug,
                'phone' => '+5511911101999',
                'direction' => 'inbound',
                'provider' => 'fake',
                'channel' => 'text',
                'body' => 'Quero a proposta e já posso confirmar a contratação.',
                'source' => 'instagram',
                'external_message_id' => 'demo-ci-ingestion-decoupled',
                'sent_at' => now()->toISOString(),
            ])
            ->assertOk();

        $lead = Lead::query()->where('company_id', $company->id)->where('phone_e164', '+5511911101999')->firstOrFail();
        $this->assertSame(1, LeadStageHistory::query()->where('lead_id', $lead->id)->count());
        $this->assertSame(0, ConversationQualityScore::query()->where('lead_id', $lead->id)->count());
    }

    public function test_old_intelligence_endpoints_are_absent(): void
    {
        $token = $this->loginToken('gestor@empresa.local');

        $this->withToken($token)->getJson('/api/v1/intelligence/sources/summary')->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/intelligence/creatives')->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/intelligence/lookalike/export')->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/intelligence/leads/1/classify-source')->assertNotFound();
        $this->withToken($token)->postJson('/api/v1/intelligence/leads/1/analyze-creative')->assertNotFound();
    }

    private function loginToken(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => '12345678',
        ])->assertOk()->json('token');
    }
}
