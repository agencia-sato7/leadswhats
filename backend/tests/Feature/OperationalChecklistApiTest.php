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

        $items = collect($response->json('data'));
        $vacuumItem = $items->first(fn (array $item) => ($item['lead_id'] ?? null) === $leadVacuum->id && ($item['task_type'] ?? null) === 'vacuum_follow_up');
        $this->assertNotNull($vacuumItem);
        $this->assertSame($conversationVacuum->id, $vacuumItem['conversation_id']);
        $this->assertSame('Novo Contato', $vacuumItem['current_stage']);
        $this->assertSame('outbound', $vacuumItem['last_message_direction']);

        $hours = (float) $vacuumItem['hours_since_last_message'];
        $this->assertGreaterThanOrEqual(12.0, $hours);

        $this->assertFalse($items->contains(fn (array $item) => ($item['lead_id'] ?? null) === $leadOtherTenant->id));

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

    public function test_waiting_first_response_appears_when_inbound_without_reply_exceeds_sla(): void
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
            'rescue_threshold_hours' => 24,
            'first_response_sla_minutes' => 15,
            'webhook_token' => null,
        ]);

        $gestor = User::create([
            'company_id' => $company->id,
            'name' => 'Gestor A',
            'email' => 'gestor.first.response@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);

        $lead = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead sem resposta',
            'phone_e164' => '+5511912340001',
            'source' => 'site',
        ]);

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'status' => 'active',
            'started_at' => now()->subHours(2),
            'last_message_at' => now()->subMinutes(30),
        ]);

        Message::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'inbound',
            'channel' => 'text',
            'body' => 'Preciso de ajuda',
            'sent_at' => now()->subMinutes(30),
            'external_message_id' => 'wamid.checklist.waiting.1',
            'metadata' => [],
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $gestor->email,
            'password' => '12345678',
        ])->json('token');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/tasks/checklist');

        $response->assertOk();
        $this->assertTrue(collect($response->json('data'))->contains(function (array $item) use ($lead): bool {
            return $item['lead_id'] === $lead->id
                && $item['task_type'] === 'waiting_first_response'
                && $item['task_label'] === 'Primeiro atendimento atrasado'
                && $item['priority'] === 'high';
        }));
        $response->assertJsonPath('meta.task_types.0', 'vacuum_follow_up');
        $response->assertJsonPath('meta.task_types.1', 'waiting_first_response');

        Carbon::setTestNow();
    }

    public function test_waiting_first_response_does_not_appear_before_sla_or_with_outbound_after_inbound(): void
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
            'rescue_threshold_hours' => 24,
            'first_response_sla_minutes' => 30,
            'webhook_token' => null,
        ]);

        $gestor = User::create([
            'company_id' => $company->id,
            'name' => 'Gestor A',
            'email' => 'gestor.first.response.rules@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);

        $leadBeforeSla = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead antes SLA',
            'phone_e164' => '+5511912340002',
            'source' => 'google',
        ]);
        $convBeforeSla = Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $leadBeforeSla->id,
            'status' => 'active',
            'started_at' => now()->subHours(1),
            'last_message_at' => now()->subMinutes(20),
        ]);
        Message::create([
            'company_id' => $company->id,
            'lead_id' => $leadBeforeSla->id,
            'conversation_id' => $convBeforeSla->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'inbound',
            'channel' => 'text',
            'body' => 'Oi',
            'sent_at' => now()->subMinutes(20),
            'external_message_id' => 'wamid.checklist.waiting.2a',
            'metadata' => [],
        ]);

        $leadWithReply = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead respondido',
            'phone_e164' => '+5511912340003',
            'source' => 'instagram',
        ]);
        $convWithReply = Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $leadWithReply->id,
            'status' => 'active',
            'started_at' => now()->subHours(2),
            'last_message_at' => now()->subMinutes(5),
        ]);
        Message::create([
            'company_id' => $company->id,
            'lead_id' => $leadWithReply->id,
            'conversation_id' => $convWithReply->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'inbound',
            'channel' => 'text',
            'body' => 'Preciso de orçamento',
            'sent_at' => now()->subMinutes(40),
            'external_message_id' => 'wamid.checklist.waiting.2b',
            'metadata' => [],
        ]);
        Message::create([
            'company_id' => $company->id,
            'lead_id' => $leadWithReply->id,
            'conversation_id' => $convWithReply->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Claro, vou te enviar',
            'sent_at' => now()->subMinutes(5),
            'external_message_id' => 'wamid.checklist.waiting.2c',
            'metadata' => [],
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $gestor->email,
            'password' => '12345678',
        ])->json('token');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/tasks/checklist');

        $response->assertOk();
        $this->assertFalse(collect($response->json('data'))->contains(fn (array $item) => $item['task_type'] === 'waiting_first_response'));

        Carbon::setTestNow();
    }

    public function test_waiting_first_response_respects_sdr_ownership_and_tenant_isolation(): void
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
            'rescue_threshold_hours' => 24,
            'first_response_sla_minutes' => 10,
            'webhook_token' => null,
        ]);

        $sdrA = User::create([
            'company_id' => $companyA->id,
            'name' => 'SDR A',
            'email' => 'sdr.waiting.owner@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'sdr',
            'active' => true,
        ]);
        $sdrB = User::create([
            'company_id' => $companyA->id,
            'name' => 'SDR B',
            'email' => 'sdr.waiting.other@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'sdr',
            'active' => true,
        ]);

        $leadOwned = Lead::create([
            'company_id' => $companyA->id,
            'owner_user_id' => $sdrA->id,
            'name' => 'Lead dono SDR A',
            'phone_e164' => '+5511912340004',
            'source' => 'site',
        ]);
        $convOwned = Conversation::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadOwned->id,
            'owner_user_id' => $sdrA->id,
            'status' => 'active',
            'started_at' => now()->subHours(1),
            'last_message_at' => now()->subMinutes(30),
        ]);
        Message::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadOwned->id,
            'conversation_id' => $convOwned->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'inbound',
            'channel' => 'text',
            'body' => 'Atendimento?',
            'sent_at' => now()->subMinutes(30),
            'external_message_id' => 'wamid.checklist.waiting.3a',
            'metadata' => [],
        ]);

        $leadOtherOwner = Lead::create([
            'company_id' => $companyA->id,
            'owner_user_id' => $sdrB->id,
            'name' => 'Lead dono SDR B',
            'phone_e164' => '+5511912340005',
            'source' => 'site',
        ]);
        $convOtherOwner = Conversation::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadOtherOwner->id,
            'owner_user_id' => $sdrB->id,
            'status' => 'active',
            'started_at' => now()->subHours(1),
            'last_message_at' => now()->subMinutes(30),
        ]);
        Message::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadOtherOwner->id,
            'conversation_id' => $convOtherOwner->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'inbound',
            'channel' => 'text',
            'body' => 'Sem resposta ainda',
            'sent_at' => now()->subMinutes(30),
            'external_message_id' => 'wamid.checklist.waiting.3b',
            'metadata' => [],
        ]);

        $leadTenantB = Lead::create([
            'company_id' => $companyB->id,
            'name' => 'Lead tenant B',
            'phone_e164' => '+5511912340006',
            'source' => 'google',
        ]);
        $convTenantB = Conversation::create([
            'company_id' => $companyB->id,
            'lead_id' => $leadTenantB->id,
            'status' => 'active',
            'started_at' => now()->subHours(1),
            'last_message_at' => now()->subMinutes(30),
        ]);
        Message::create([
            'company_id' => $companyB->id,
            'lead_id' => $leadTenantB->id,
            'conversation_id' => $convTenantB->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'inbound',
            'channel' => 'text',
            'body' => 'Tenant B sem resposta',
            'sent_at' => now()->subMinutes(30),
            'external_message_id' => 'wamid.checklist.waiting.3c',
            'metadata' => [],
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $sdrA->email,
            'password' => '12345678',
        ])->json('token');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/tasks/checklist');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.lead_id', $leadOwned->id);
        $response->assertJsonPath('data.0.task_type', 'waiting_first_response');

        Carbon::setTestNow();
    }
}
