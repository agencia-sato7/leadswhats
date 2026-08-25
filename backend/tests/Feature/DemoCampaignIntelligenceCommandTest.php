<?php

namespace Tests\Feature;

use App\Models\CampaignIntelligenceEvidence;
use App\Models\CampaignIntelligenceReport;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoCampaignIntelligenceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_an_idempotent_completed_presentation_campaign(): void
    {
        config()->set('intelligence.analyzer', 'unavailable');

        $this->artisan('leadswhats:demo-campaign')
            ->expectsOutputToContain('Campanha de demonstração preparada.')
            ->assertExitCode(0);

        $report = CampaignIntelligenceReport::query()->sole();
        $this->assertSame('completed', $report->status);
        $this->assertSame(1, $report->metrics['rescued_leads']);
        $this->assertGreaterThan(0, $report->metrics['new_leads']);
        $this->assertSame(1, $report->result['cohorts']['rescued']['lead_count']);
        $this->assertNotEmpty($report->result['executive_summary']);
        $this->assertSame('fake', $report->model_provider);

        $this->assertDatabaseHas('leads', [
            'name' => 'Marcelo Pires',
            'campaign_name' => 'Instagram | Retomada de Implantes',
            'is_repeat_lead' => true,
        ]);
        $this->assertSame(1, Message::query()->where('is_rescue', true)->count());
        $this->assertGreaterThan(0, CampaignIntelligenceEvidence::query()->count());

        $evidenceCount = CampaignIntelligenceEvidence::query()->count();
        $this->artisan('leadswhats:demo-campaign')->assertExitCode(0);

        $this->assertSame(1, CampaignIntelligenceReport::query()->count());
        $this->assertSame($evidenceCount, CampaignIntelligenceEvidence::query()->count());
        $this->assertSame(1, Lead::query()->where('phone_e164', '+5511911101099')->count());
        $this->assertSame(4, Message::query()->where('external_message_id', 'like', 'demo-campaign-rescue-%')->count());
    }
}
