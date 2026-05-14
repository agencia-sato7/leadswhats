<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Pipeline;
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

    public function test_webhook_places_new_lead_in_initial_default_pipeline_column_and_records_system_history(): void
    {
        $company = Company::create(['name' => 'Empresa Kanban', 'slug' => 'empresa-kanban']);

        $pipeline = Pipeline::create([
            'company_id' => $company->id,
            'name' => 'Pipeline Comercial',
            'is_default' => true,
        ]);

        $initialColumn = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Novo Contato',
            'position' => 1,
        ]);

        KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Negociação',
            'position' => 2,
        ]);

        $gestor = User::create([
            'company_id' => $company->id,
            'name' => 'Gestor Kanban',
            'email' => 'gestor.kanban.webhook@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);

        $headers = ['X-Webhook-Token' => 'leadswhats-dev-token'];

        $this->postJson('/api/v1/webhooks/whatsapp', [
            'company_slug' => $company->slug,
            'phone' => '(11) 95555-1111',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'external_message_id' => 'wamid.kanban.init.1',
            'sent_at' => '2026-05-06T10:00:00-03:00',
        ], $headers)->assertOk()->assertJsonPath('data.duplicated', false);

        $lead = Lead::query()
            ->where('company_id', $company->id)
            ->where('phone_e164', '+5511955551111')
            ->firstOrFail();

        $this->assertDatabaseHas('lead_stage_histories', [
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'from_column_id' => null,
            'to_column_id' => $initialColumn->id,
            'moved_by_user_id' => null,
            'move_source' => 'system',
            'reason' => 'Lead criado via webhook',
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $gestor->email,
            'password' => '12345678',
        ])->json('token');

        $kanban = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/pipelines/' . $pipeline->id . '/kanban')
            ->assertOk()
            ->json();

        $this->assertSame($lead->id, $kanban['data']['columns'][0]['cards'][0]['lead_id']);
    }

    public function test_webhook_does_not_duplicate_stage_history_when_lead_already_has_stage(): void
    {
        $company = Company::create(['name' => 'Empresa Kanban', 'slug' => 'empresa-kanban']);

        $pipeline = Pipeline::create([
            'company_id' => $company->id,
            'name' => 'Pipeline Comercial',
            'is_default' => true,
        ]);

        $initialColumn = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Novo Contato',
            'position' => 1,
        ]);

        $lead = Lead::create([
            'company_id' => $company->id,
            'phone_e164' => '+5511955552222',
            'source' => 'desconhecido',
        ]);

        LeadStageHistory::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'from_column_id' => null,
            'to_column_id' => $initialColumn->id,
            'moved_by_user_id' => null,
            'move_source' => 'system',
            'reason' => 'Lead criado via webhook',
            'moved_at' => now()->subMinute(),
        ]);

        $headers = ['X-Webhook-Token' => 'leadswhats-dev-token'];

        $this->postJson('/api/v1/webhooks/whatsapp', [
            'company_slug' => $company->slug,
            'phone' => '(11) 95555-2222',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'external_message_id' => 'wamid.kanban.init.2',
            'sent_at' => '2026-05-06T10:00:00-03:00',
        ], $headers)->assertOk();

        $this->assertSame(
            1,
            LeadStageHistory::query()
                ->where('company_id', $company->id)
                ->where('lead_id', $lead->id)
                ->count()
        );
    }

    public function test_webhook_keeps_working_when_company_has_no_pipeline_or_columns(): void
    {
        $company = Company::create(['name' => 'Empresa Sem Pipeline', 'slug' => 'empresa-sem-pipeline']);
        $headers = ['X-Webhook-Token' => 'leadswhats-dev-token'];

        $this->postJson('/api/v1/webhooks/whatsapp', [
            'company_slug' => $company->slug,
            'phone' => '(11) 95555-3333',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'external_message_id' => 'wamid.kanban.init.3',
            'sent_at' => '2026-05-06T10:00:00-03:00',
        ], $headers)->assertOk()->assertJsonPath('data.duplicated', false);

        $lead = Lead::query()
            ->where('company_id', $company->id)
            ->where('phone_e164', '+5511955553333')
            ->firstOrFail();

        $this->assertDatabaseMissing('lead_stage_histories', [
            'company_id' => $company->id,
            'lead_id' => $lead->id,
        ]);
    }

    public function test_webhook_places_each_tenant_lead_in_its_own_default_pipeline_initial_column(): void
    {
        $companyA = Company::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $companyB = Company::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);

        $pipelineA = Pipeline::create([
            'company_id' => $companyA->id,
            'name' => 'Pipeline A',
            'is_default' => true,
        ]);
        $pipelineB = Pipeline::create([
            'company_id' => $companyB->id,
            'name' => 'Pipeline B',
            'is_default' => true,
        ]);

        $columnA = KanbanColumn::create([
            'company_id' => $companyA->id,
            'pipeline_id' => $pipelineA->id,
            'name' => 'Novo A',
            'position' => 1,
        ]);
        $columnB = KanbanColumn::create([
            'company_id' => $companyB->id,
            'pipeline_id' => $pipelineB->id,
            'name' => 'Novo B',
            'position' => 1,
        ]);

        $headers = ['X-Webhook-Token' => 'leadswhats-dev-token'];

        $this->postJson('/api/v1/webhooks/whatsapp', [
            'company_slug' => $companyA->slug,
            'phone' => '(11) 95555-4444',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'external_message_id' => 'wamid.kanban.init.4a',
            'sent_at' => '2026-05-06T10:00:00-03:00',
        ], $headers)->assertOk();

        $this->postJson('/api/v1/webhooks/whatsapp', [
            'company_slug' => $companyB->slug,
            'phone' => '(11) 95555-4445',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'external_message_id' => 'wamid.kanban.init.4b',
            'sent_at' => '2026-05-06T10:00:00-03:00',
        ], $headers)->assertOk();

        $leadA = Lead::query()->where('company_id', $companyA->id)->where('phone_e164', '+5511955554444')->firstOrFail();
        $leadB = Lead::query()->where('company_id', $companyB->id)->where('phone_e164', '+5511955554445')->firstOrFail();

        $this->assertDatabaseHas('lead_stage_histories', [
            'company_id' => $companyA->id,
            'lead_id' => $leadA->id,
            'to_column_id' => $columnA->id,
            'move_source' => 'system',
        ]);

        $this->assertDatabaseHas('lead_stage_histories', [
            'company_id' => $companyB->id,
            'lead_id' => $leadB->id,
            'to_column_id' => $columnB->id,
            'move_source' => 'system',
        ]);

        $this->assertDatabaseMissing('lead_stage_histories', [
            'company_id' => $companyA->id,
            'lead_id' => $leadA->id,
            'to_column_id' => $columnB->id,
        ]);

        $this->assertDatabaseMissing('lead_stage_histories', [
            'company_id' => $companyB->id,
            'lead_id' => $leadB->id,
            'to_column_id' => $columnA->id,
        ]);
    }
}
