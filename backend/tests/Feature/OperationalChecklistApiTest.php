<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Message;
use App\Models\Pipeline;
use App\Models\KanbanColumn;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OperationalChecklistApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_gets_401_on_tasks_checklist(): void
    {
        $this->getJson('/api/v1/tasks/checklist')->assertUnauthorized();
    }

    public function test_gestor_lists_vacuum_items_respecting_threshold_and_tenant_isolation(): void
    {
        Carbon::setTestNow('2026-05-07 12:00:00');

        $companyA = Company::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);
        $companyB = Company::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);

        CompanyBusinessSetting::create([
            'company_id' => $companyA->id,
            'timezone' => 'America/Sao_Paulo',
            'workday_start_time' => '08:00:00',
            'workday_end_time' => '18:00:00',
            'lunch_start_time' => '12:00:00',
            'lunch_end_time' => '13:00:00',
            'working_days' => [1, 2, 3, 4, 5],
            'repeated_lead_window_days' => 90,
            'rescue_threshold_hours' => 12,
            'webhook_token' => null,
        ]);

        $gestor = User::create([
            'company_id' => $companyA->id,
            'name' => 'Gestor A',
            'email' => 'gestor.checklist@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);

        $pipeline = Pipeline::create([
            'company_id' => $companyA->id,
            'name' => 'Pipeline A',
            'is_default' => true,
        ]);
        $column = KanbanColumn::create([
            'company_id' => $companyA->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Novo Contato',
            'position' => 1,
        ]);

        $leadVacuum = Lead::create([
            'company_id' => $companyA->id,
            'name' => 'Lead Vacuum',
            'phone_e164' => '+5511990000001',
            'source' => 'google',
        ]);

        LeadStageHistory::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadVacuum->id,
            'from_column_id' => null,
            'to_column_id' => $column->id,
            'moved_by_user_id' => null,
            'move_source' => 'system',
            'reason' => 'Lead criado via webhook',
            'moved_at' => now()->subHours(20),
        ]);

        $conversationVacuum = Conversation::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadVacuum->id,
            'status' => 'active',
            'started_at' => now()->subHours(30),
            'last_message_at' => now()->subHours(13),
        ]);

        Message::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadVacuum->id,
            'conversation_id' => $conversationVacuum->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Retorno comercial',
            'sent_at' => now()->subHours(13),
            'external_message_id' => 'wamid.checklist.vacuum.1',
            'metadata' => [],
        ]);

        $leadBeforeThreshold = Lead::create([
            'company_id' => $companyA->id,
            'name' => 'Lead Recente',
            'phone_e164' => '+5511990000002',
            'source' => 'instagram',
        ]);

        $conversationBeforeThreshold = Conversation::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadBeforeThreshold->id,
            'status' => 'active',
            'started_at' => now()->subHours(6),
            'last_message_at' => now()->subHours(11),
        ]);

        Message::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadBeforeThreshold->id,
            'conversation_id' => $conversationBeforeThreshold->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Mensagem recente',
            'sent_at' => now()->subHours(11),
            'external_message_id' => 'wamid.checklist.vacuum.2',
            'metadata' => [],
        ]);

        $leadInboundAfter = Lead::create([
            'company_id' => $companyA->id,
            'name' => 'Lead Respondeu',
            'phone_e164' => '+5511990000003',
            'source' => 'site',
        ]);

        $conversationInboundAfter = Conversation::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadInboundAfter->id,
            'status' => 'active',
            'started_at' => now()->subHours(20),
            'last_message_at' => now()->subHours(1),
        ]);

        Message::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadInboundAfter->id,
            'conversation_id' => $conversationInboundAfter->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Primeiro retorno',
            'sent_at' => now()->subHours(14),
            'external_message_id' => 'wamid.checklist.vacuum.3a',
            'metadata' => [],
        ]);

        Message::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadInboundAfter->id,
            'conversation_id' => $conversationInboundAfter->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'inbound',
            'channel' => 'text',
            'body' => 'Cliente respondeu',
            'sent_at' => now()->subHours(1),
            'external_message_id' => 'wamid.checklist.vacuum.3b',
            'metadata' => [],
        ]);

        $leadOtherTenant = Lead::create([
            'company_id' => $companyB->id,
            'name' => 'Lead Outra Empresa',
            'phone_e164' => '+5511990000004',
            'source' => 'google',
        ]);

        $conversationOtherTenant = Conversation::create([
            'company_id' => $companyB->id,
            'lead_id' => $leadOtherTenant->id,
            'status' => 'active',
            'started_at' => now()->subHours(40),
            'last_message_at' => now()->subHours(20),
        ]);

        Message::create([
            'company_id' => $companyB->id,
            'lead_id' => $leadOtherTenant->id,
            'conversation_id' => $conversationOtherTenant->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Mensagem outra empresa',
            'sent_at' => now()->subHours(20),
            'external_message_id' => 'wamid.checklist.vacuum.4',
            'metadata' => [],
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $gestor->email,
            'password' => '12345678',
        ])->json('token');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/tasks/checklist');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.lead_id', $leadVacuum->id);
        $response->assertJsonPath('data.0.conversation_id', $conversationVacuum->id);
        $response->assertJsonPath('data.0.task_type', 'vacuum_follow_up');
        $response->assertJsonPath('data.0.current_stage', 'Novo Contato');
        $response->assertJsonPath('data.0.last_message_direction', 'outbound');

        $hours = (float) $response->json('data.0.hours_since_last_message');
        $this->assertGreaterThanOrEqual(12.0, $hours);

        Carbon::setTestNow();
    }

    public function test_sdr_checklist_is_limited_to_owned_scope(): void
    {
        Carbon::setTestNow('2026-05-07 12:00:00');

        $company = Company::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);

        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'timezone' => 'America/Sao_Paulo',
            'workday_start_time' => '08:00:00',
            'workday_end_time' => '18:00:00',
            'lunch_start_time' => '12:00:00',
            'lunch_end_time' => '13:00:00',
            'working_days' => [1, 2, 3, 4, 5],
            'repeated_lead_window_days' => 90,
            'rescue_threshold_hours' => 12,
            'webhook_token' => null,
        ]);

        $sdr = User::create([
            'company_id' => $company->id,
            'name' => 'SDR A',
            'email' => 'sdr.checklist@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'sdr',
            'active' => true,
        ]);

        $otherSdr = User::create([
            'company_id' => $company->id,
            'name' => 'SDR B',
            'email' => 'sdr.checklist.other@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'sdr',
            'active' => true,
        ]);

        $leadOwned = Lead::create([
            'company_id' => $company->id,
            'owner_user_id' => $sdr->id,
            'name' => 'Lead SDR A',
            'phone_e164' => '+5511991111111',
            'source' => 'google',
        ]);

        $convOwned = Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $leadOwned->id,
            'owner_user_id' => $sdr->id,
            'status' => 'active',
            'started_at' => now()->subHours(20),
            'last_message_at' => now()->subHours(13),
        ]);

        Message::create([
            'company_id' => $company->id,
            'lead_id' => $leadOwned->id,
            'conversation_id' => $convOwned->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Follow-up SDR A',
            'sent_at' => now()->subHours(13),
            'external_message_id' => 'wamid.checklist.sdr.1',
            'metadata' => [],
        ]);

        $leadOther = Lead::create([
            'company_id' => $company->id,
            'owner_user_id' => $otherSdr->id,
            'name' => 'Lead SDR B',
            'phone_e164' => '+5511992222222',
            'source' => 'instagram',
        ]);

        $convOther = Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $leadOther->id,
            'owner_user_id' => $otherSdr->id,
            'status' => 'active',
            'started_at' => now()->subHours(20),
            'last_message_at' => now()->subHours(13),
        ]);

        Message::create([
            'company_id' => $company->id,
            'lead_id' => $leadOther->id,
            'conversation_id' => $convOther->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Follow-up SDR B',
            'sent_at' => now()->subHours(13),
            'external_message_id' => 'wamid.checklist.sdr.2',
            'metadata' => [],
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $sdr->email,
            'password' => '12345678',
        ])->json('token');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/tasks/checklist');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.lead_id', $leadOwned->id);

        Carbon::setTestNow();
    }
}
