<?php

namespace Tests\Feature;

use App\Models\CampaignIntelligenceEvidence;
use App\Models\CampaignIntelligenceReport;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignIntelligenceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('intelligence.analyzer', 'fake');
        config()->set('queue.default', 'sync');
        Http::preventStrayRequests();
        $this->artisan('leadswhats:demo-bootstrap')->assertExitCode(0);
    }

    public function test_preview_validates_closed_range_and_equal_previous_period(): void
    {
        $token = $this->loginToken('gestor@empresa.local');
        $end = $this->campaignToday()->subDay();
        $start = $end->copy()->subDays(4);

        $this->withToken($token)->getJson('/api/v1/intelligence/campaign-reports/preview?'.http_build_query([
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]))->assertOk()
            ->assertJsonPath('data.range.days', 5)
            ->assertJsonPath('data.range.comparison_end_date', $start->copy()->subDay()->toDateString())
            ->assertJsonStructure(['data' => ['metrics' => ['volume' => [
                'current_new_leads', 'previous_new_leads', 'absolute_change', 'percentage_change', 'trend',
            ]]]]);

        $this->withToken($token)->getJson('/api/v1/intelligence/campaign-reports/preview?'.http_build_query([
            'start_date' => $this->campaignToday()->subDays(2)->toDateString(),
            'end_date' => $this->campaignToday()->toDateString(),
        ]))->assertUnprocessable()->assertJsonPath('message', 'A data final deve ser, no máximo, ontem no fuso da empresa.');

        $this->withToken($token)->getJson('/api/v1/intelligence/campaign-reports/preview?'.http_build_query([
            'start_date' => $this->campaignToday()->subDays(100)->toDateString(),
            'end_date' => $this->campaignToday()->subDay()->toDateString(),
        ]))->assertUnprocessable()->assertJsonPath('message', 'O período máximo permitido é de 90 dias.');
    }

    public function test_new_and_rescued_cohorts_are_disjoint_and_report_is_persisted(): void
    {
        $company = Company::query()->where('slug', 'empresa-demo')->firstOrFail();
        $oldLead = Lead::query()->create([
            'company_id' => $company->id,
            'name' => 'Lead antigo resgatado',
            'phone_e164' => '+5511900000088',
            'source' => 'instagram',
        ]);
        $oldLead->forceFill(['created_at' => $this->campaignToday()->subDays(30), 'updated_at' => $this->campaignToday()->subDays(30)])->save();
        $conversation = Conversation::query()->create([
            'company_id' => $company->id,
            'lead_id' => $oldLead->id,
            'status' => 'active',
            'started_at' => $this->campaignToday()->subDays(30),
            'last_message_at' => $this->campaignToday()->subDays(2),
        ]);
        Message::query()->create([
            'company_id' => $company->id,
            'lead_id' => $oldLead->id,
            'conversation_id' => $conversation->id,
            'provider' => 'fake',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Podemos retomar sua avaliação?',
            'sent_at' => $this->campaignToday()->subDays(2)->setTime(10, 0),
            'external_message_id' => 'campaign-rescue-1',
            'is_rescue' => true,
        ]);
        Message::query()->create([
            'company_id' => $company->id,
            'lead_id' => $oldLead->id,
            'conversation_id' => $conversation->id,
            'provider' => 'fake',
            'direction' => 'inbound',
            'channel' => 'text',
            'body' => 'Sim, quero retomar.',
            'sent_at' => $this->campaignToday()->subDays(2)->setTime(10, 10),
            'external_message_id' => 'campaign-rescue-2',
        ]);

        $token = $this->loginToken('gestor@empresa.local');
        $payload = [
            'start_date' => $this->campaignToday()->subDays(7)->toDateString(),
            'end_date' => $this->campaignToday()->subDay()->toDateString(),
        ];
        $preview = $this->withToken($token)->getJson('/api/v1/intelligence/campaign-reports/preview?'.http_build_query($payload))
            ->assertOk()->json('data');
        $this->assertGreaterThan(0, $preview['metrics']['new_leads']);
        $this->assertSame(1, $preview['metrics']['rescued_leads']);
        $this->assertEquals(100.0, $preview['metrics']['rescue_response_rate']);

        $response = $this->withToken($token)->postJson('/api/v1/intelligence/campaign-reports', $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.result.cohorts.rescued.lead_count', 1)
            ->assertJsonStructure(['data' => ['result' => [
                'executive_summary', 'overall_verdict', 'volume_assessment', 'service_quality', 'cohorts', 'team', 'priorities',
            ]]]);
        $reportId = (int) $response->json('data.id');

        $this->withToken($token)->getJson("/api/v1/intelligence/campaign-reports/{$reportId}/leads?cohort=rescued")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.phone');
        $this->assertDatabaseCount('campaign_intelligence_reports', 1);
        $this->assertGreaterThan(0, CampaignIntelligenceEvidence::query()->count());
    }

    public function test_exact_range_is_reused_and_extension_reuses_evidences(): void
    {
        $token = $this->loginToken('gestor@empresa.local');
        $start = $this->campaignToday()->subDays(7)->toDateString();
        $firstPayload = ['start_date' => $start, 'end_date' => $this->campaignToday()->subDays(2)->toDateString()];
        $first = $this->withToken($token)->postJson('/api/v1/intelligence/campaign-reports', $firstPayload)->assertOk();
        $evidenceCount = CampaignIntelligenceEvidence::query()->count();

        $this->withToken($token)->postJson('/api/v1/intelligence/campaign-reports', $firstPayload)
            ->assertOk()->assertJsonPath('reused', true)->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('campaign_intelligence_reports', 1);
        $this->assertSame($evidenceCount, CampaignIntelligenceEvidence::query()->count());

        $extended = $this->withToken($token)->postJson('/api/v1/intelligence/campaign-reports', [
            'start_date' => $start,
            'end_date' => $this->campaignToday()->subDay()->toDateString(),
        ])->assertOk();
        $this->assertSame((int) $first->json('data.id'), (int) $extended->json('data.base_report_id'));
        $this->assertGreaterThan(0, (int) $extended->json('data.reused_evidence_count'));
        $this->assertDatabaseCount('campaign_intelligence_reports', 2);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $query = http_build_query([
            'start_date' => $this->campaignToday()->subDays(7)->toDateString(),
            'end_date' => $this->campaignToday()->subDay()->toDateString(),
        ]);
        $this->withToken($this->loginToken('sdr@empresa.local'))
            ->getJson("/api/v1/intelligence/campaign-reports/preview?{$query}")->assertForbidden();

        $token = $this->loginToken('gestor@empresa.local');
        $reportId = $this->withToken($token)->postJson('/api/v1/intelligence/campaign-reports', [
            'start_date' => $this->campaignToday()->subDays(7)->toDateString(),
            'end_date' => $this->campaignToday()->subDay()->toDateString(),
        ])->assertOk()->json('data.id');

        $otherCompany = Company::query()->create(['name' => 'Outra', 'slug' => 'outra-campaign']);
        $otherManager = \App\Models\User::query()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Gestor externo',
            'email' => 'campaign@outra.local',
            'password' => '12345678',
            'role' => 'gestor',
            'active' => true,
        ]);
        $otherToken = $this->postJson('/api/v1/auth/login', ['email' => $otherManager->email, 'password' => '12345678'])->assertOk()->json('token');
        $this->withToken($otherToken)->getJson("/api/v1/intelligence/campaign-reports/{$reportId}")->assertNotFound();
    }

    private function loginToken(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => '12345678',
        ])->assertOk()->json('token');
    }

    private function campaignToday(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Sao_Paulo')->startOfDay();
    }
}
