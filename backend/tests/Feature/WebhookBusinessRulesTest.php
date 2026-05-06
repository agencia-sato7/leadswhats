<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Lead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WebhookBusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_idempotent_replay_does_not_inflate_dashboard_metrics(): void
    {
        Carbon::setTestNow('2026-05-06 15:00:00');

        $company = Company::create([
            'name' => 'Empresa Métrica',
            'slug' => 'empresa-metrica',
            'timezone' => 'America/Sao_Paulo',
        ]);

        $gestor = User::create([
            'company_id' => $company->id,
            'name' => 'Gestor Métrica',
            'email' => 'gestor.metrica@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);

        $payload = [
            'company_slug' => $company->slug,
            'phone' => '(11) 97777-1111',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'channel' => 'text',
            'body' => 'Quero orçamento',
            'source' => 'google',
            'external_message_id' => 'wamid.metric.1',
            'sent_at' => '2026-05-06T10:00:00-03:00',
        ];

        $headers = ['X-Webhook-Token' => 'leadswhats-dev-token'];

        $this->postJson('/api/v1/webhooks/whatsapp', $payload, $headers)->assertOk();
        $this->postJson('/api/v1/webhooks/whatsapp', $payload, $headers)
            ->assertOk()
            ->assertJsonPath('data.duplicated', true);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $gestor->email,
            'password' => '12345678',
        ])->json('token');

        $summary = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk()
            ->json();

        $this->assertSame(1, $summary['metrics']['new_leads_today']);
        $this->assertSame(0, $summary['metrics']['repeat_leads_today']);
        $this->assertSame(1, $summary['metrics']['active_conversations']);

        Carbon::setTestNow();
    }

    public function test_webhook_respects_company_slug_for_tenant_isolation(): void
    {
        $companyA = Company::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $companyB = Company::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);

        $headers = ['X-Webhook-Token' => 'leadswhats-dev-token'];

        $this->postJson('/api/v1/webhooks/whatsapp', [
            'company_slug' => $companyA->slug,
            'phone' => '(11) 96666-1111',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'external_message_id' => 'wamid.tenant.a',
            'sent_at' => '2026-05-06T10:00:00-03:00',
        ], $headers)->assertOk();

        $this->postJson('/api/v1/webhooks/whatsapp', [
            'company_slug' => $companyB->slug,
            'phone' => '(11) 96666-1111',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'external_message_id' => 'wamid.tenant.b',
            'sent_at' => '2026-05-06T10:00:00-03:00',
        ], $headers)->assertOk();

        $this->assertSame(1, Lead::where('company_id', $companyA->id)->count());
        $this->assertSame(1, Lead::where('company_id', $companyB->id)->count());
        $this->assertSame(2, Lead::count());
    }
}
