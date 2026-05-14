<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Message;
use App\Models\Pipeline;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ContactsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_gets_401_on_contacts_endpoint(): void
    {
        $this->getJson('/api/v1/contacts')->assertUnauthorized();
    }

    public function test_gestor_lists_company_contacts_with_supported_filters_and_pagination(): void
    {
        Carbon::setTestNow('2026-05-07 12:00:00');

        [$companyA, $gestorA, $columnA] = $this->seedCompanyUserAndColumn('empresa-a', 'gestor.contacts@test.local');
        [$companyB] = $this->seedCompanyUserAndColumn('empresa-b', 'gestor.contacts.b@test.local');

        $leadA1 = Lead::create([
            'company_id' => $companyA->id,
            'name' => 'Maria Silva',
            'phone_e164' => '+5511991111111',
            'source' => 'instagram',
            'source_method' => 'manual',
            'is_repeat_lead' => false,
        ]);
        $leadA1->forceFill([
            'created_at' => Carbon::parse('2026-05-05 10:00:00'),
            'updated_at' => Carbon::parse('2026-05-05 10:00:00'),
        ])->save();

        $leadA2 = Lead::create([
            'company_id' => $companyA->id,
            'name' => 'Joao Mendes',
            'phone_e164' => '+5511992222222',
            'source' => 'google',
            'source_method' => 'auto',
            'is_repeat_lead' => true,
        ]);
        $leadA2->forceFill([
            'created_at' => Carbon::parse('2026-05-06 11:00:00'),
            'updated_at' => Carbon::parse('2026-05-06 11:00:00'),
        ])->save();

        $leadA3 = Lead::create([
            'company_id' => $companyA->id,
            'name' => 'Unknown Source',
            'phone_e164' => '+5511993333333',
            'source' => 'desconhecido',
            'source_method' => 'auto',
            'is_repeat_lead' => false,
        ]);
        $leadA3->forceFill([
            'created_at' => Carbon::parse('2026-05-07 08:00:00'),
            'updated_at' => Carbon::parse('2026-05-07 08:00:00'),
        ])->save();

        $leadB = Lead::create([
            'company_id' => $companyB->id,
            'name' => 'Lead Tenant B',
            'phone_e164' => '+5511988888888',
            'source' => 'google',
            'source_method' => 'auto',
            'is_repeat_lead' => false,
        ]);

        LeadStageHistory::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadA1->id,
            'from_column_id' => null,
            'to_column_id' => $columnA->id,
            'moved_by_user_id' => $gestorA->id,
            'move_source' => 'manual',
            'reason' => 'Entrada',
            'moved_at' => Carbon::parse('2026-05-05 10:05:00'),
        ]);

        $conversationA1 = Conversation::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadA1->id,
            'status' => 'active',
            'started_at' => Carbon::parse('2026-05-05 10:00:00'),
            'last_message_at' => Carbon::parse('2026-05-07 09:00:00'),
        ]);

        Message::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadA1->id,
            'conversation_id' => $conversationA1->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Follow-up',
            'sent_at' => Carbon::parse('2026-05-07 09:00:00'),
            'external_message_id' => 'wamid.contacts.a1',
        ]);

        $conversationA2 = Conversation::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadA2->id,
            'status' => 'active',
            'started_at' => Carbon::parse('2026-05-06 11:00:00'),
            'last_message_at' => Carbon::parse('2026-05-07 10:00:00'),
        ]);

        Message::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadA2->id,
            'conversation_id' => $conversationA2->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'inbound',
            'channel' => 'text',
            'body' => 'Oi',
            'sent_at' => Carbon::parse('2026-05-07 10:00:00'),
            'external_message_id' => 'wamid.contacts.a2',
        ]);

        $conversationA3 = Conversation::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadA3->id,
            'status' => 'active',
            'started_at' => Carbon::parse('2026-05-07 08:00:00'),
            'last_message_at' => Carbon::parse('2026-05-07 11:00:00'),
        ]);

        Message::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadA3->id,
            'conversation_id' => $conversationA3->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Primeira tentativa',
            'sent_at' => Carbon::parse('2026-05-07 11:00:00'),
            'external_message_id' => 'wamid.contacts.a3',
        ]);

        $conversationB = Conversation::create([
            'company_id' => $companyB->id,
            'lead_id' => $leadB->id,
            'status' => 'active',
            'started_at' => Carbon::parse('2026-05-07 10:00:00'),
            'last_message_at' => Carbon::parse('2026-05-07 11:30:00'),
        ]);

        Message::create([
            'company_id' => $companyB->id,
            'lead_id' => $leadB->id,
            'conversation_id' => $conversationB->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Tenant B',
            'sent_at' => Carbon::parse('2026-05-07 11:30:00'),
            'external_message_id' => 'wamid.contacts.b1',
        ]);

        $token = $this->login($gestorA->email);

        $all = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/contacts');
        $all->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.lead_id', $leadA3->id);

        $allIds = collect($all->json('data'))->pluck('lead_id')->all();
        $this->assertContains($leadA1->id, $allIds);
        $this->assertContains($leadA2->id, $allIds);
        $this->assertContains($leadA3->id, $allIds);
        $this->assertNotContains($leadB->id, $allIds);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/contacts?source=instagram')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.lead_id', $leadA1->id);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/contacts?classification=lead_repetido')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.lead_id', $leadA2->id);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/contacts?search=Maria')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.lead_id', $leadA1->id);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/contacts?search=3333333')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.lead_id', $leadA3->id);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/contacts?stage_id=' . $columnA->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.lead_id', $leadA1->id)
            ->assertJsonPath('data.0.current_stage', $columnA->name);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/contacts?kanban_column_id=' . $columnA->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.lead_id', $leadA1->id)
            ->assertJsonPath('data.0.current_stage', $columnA->name);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/contacts?has_unknown_source=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.lead_id', $leadA3->id);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/contacts?created_from=2026-05-06&created_to=2026-05-06')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.lead_id', $leadA2->id);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/contacts?last_message_from=2026-05-07%2009:30:00')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/contacts?last_message_to=2026-05-07%2009:30:00')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.lead_id', $leadA1->id);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/contacts?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data');

        Carbon::setTestNow();
    }

    public function test_sdr_only_sees_owned_contacts_from_lead_or_conversation_owner(): void
    {
        $company = Company::create(['name' => 'Empresa A', 'slug' => 'empresa-a']);

        $sdrA = User::create([
            'company_id' => $company->id,
            'name' => 'SDR A',
            'email' => 'sdr.contacts.a@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'sdr',
            'active' => true,
        ]);

        $sdrB = User::create([
            'company_id' => $company->id,
            'name' => 'SDR B',
            'email' => 'sdr.contacts.b@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'sdr',
            'active' => true,
        ]);

        $leadOwnedByLead = Lead::create([
            'company_id' => $company->id,
            'owner_user_id' => $sdrA->id,
            'name' => 'Lead owner lead',
            'phone_e164' => '+5511944440001',
            'source' => 'google',
        ]);

        $leadOwnedByConversation = Lead::create([
            'company_id' => $company->id,
            'owner_user_id' => null,
            'name' => 'Lead owner conversation',
            'phone_e164' => '+5511944440002',
            'source' => 'google',
        ]);

        $leadOtherSdr = Lead::create([
            'company_id' => $company->id,
            'owner_user_id' => $sdrB->id,
            'name' => 'Lead other SDR',
            'phone_e164' => '+5511944440003',
            'source' => 'google',
        ]);

        $leadWithoutOwner = Lead::create([
            'company_id' => $company->id,
            'owner_user_id' => null,
            'name' => 'Lead sem dono',
            'phone_e164' => '+5511944440004',
            'source' => 'google',
        ]);

        $convOwnedByLead = Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $leadOwnedByLead->id,
            'owner_user_id' => null,
            'status' => 'active',
            'started_at' => now()->subHours(2),
            'last_message_at' => now()->subHours(1),
        ]);

        $convOwnedByConversation = Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $leadOwnedByConversation->id,
            'owner_user_id' => $sdrA->id,
            'status' => 'active',
            'started_at' => now()->subHours(2),
            'last_message_at' => now()->subHours(1),
        ]);

        $convOtherSdr = Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $leadOtherSdr->id,
            'owner_user_id' => $sdrB->id,
            'status' => 'active',
            'started_at' => now()->subHours(2),
            'last_message_at' => now()->subHours(1),
        ]);

        $convWithoutOwner = Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $leadWithoutOwner->id,
            'owner_user_id' => null,
            'status' => 'active',
            'started_at' => now()->subHours(2),
            'last_message_at' => now()->subHours(1),
        ]);

        $pairs = [
            [$leadOwnedByLead, $convOwnedByLead],
            [$leadOwnedByConversation, $convOwnedByConversation],
            [$leadOtherSdr, $convOtherSdr],
            [$leadWithoutOwner, $convWithoutOwner],
        ];

        foreach ($pairs as $index => [$lead, $conversation]) {
            Message::create([
                'company_id' => $company->id,
                'lead_id' => $lead->id,
                'conversation_id' => $conversation->id,
                'provider' => 'whatsapp-cloud',
                'direction' => 'outbound',
                'channel' => 'text',
                'body' => 'Msg',
                'sent_at' => now()->subMinutes(30 + $index),
                'external_message_id' => 'wamid.contacts.sdr.' . $lead->id,
            ]);
        }

        $token = $this->login($sdrA->email);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/contacts');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('lead_id')->all();

        $this->assertContains($leadOwnedByLead->id, $ids);
        $this->assertContains($leadOwnedByConversation->id, $ids);
        $this->assertNotContains($leadOtherSdr->id, $ids);
        $this->assertNotContains($leadWithoutOwner->id, $ids);
    }

    public function test_gestor_can_export_contacts_csv(): void
    {
        [$company, $gestor, $column] = $this->seedCompanyUserAndColumn('empresa-export-gestor', 'gestor.contacts.export@test.local');

        $lead = Lead::create([
            'company_id' => $company->id,
            'name' => 'Maria Export',
            'phone_e164' => '+5511912345678',
            'source' => 'instagram',
            'source_method' => 'manual',
            'is_repeat_lead' => false,
        ]);

        LeadStageHistory::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'from_column_id' => null,
            'to_column_id' => $column->id,
            'moved_by_user_id' => $gestor->id,
            'move_source' => 'manual',
            'reason' => 'Entrada',
            'moved_at' => now()->subHour(),
        ]);

        Message::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'conversation_id' => Conversation::create([
                'company_id' => $company->id,
                'lead_id' => $lead->id,
                'status' => 'active',
                'started_at' => now()->subHours(2),
                'last_message_at' => now()->subHour(),
            ])->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Teste export',
            'sent_at' => now()->subHour(),
            'external_message_id' => 'wamid.contacts.export.gestor',
        ]);

        $token = $this->login($gestor->email);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get('/api/v1/contacts/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('phone,name,source,classification,current_stage,created_at,last_message_at', $csv);
        $this->assertStringContainsString('+5511912345678', $csv);
        $this->assertStringContainsString('Maria Export', $csv);
        $this->assertStringContainsString('instagram', $csv);
    }

    public function test_admin_can_export_contacts_csv(): void
    {
        $company = Company::create([
            'name' => 'Empresa Admin Export',
            'slug' => 'empresa-admin-export',
            'timezone' => 'America/Sao_Paulo',
        ]);

        $admin = User::create([
            'company_id' => $company->id,
            'name' => 'Admin Export',
            'email' => 'admin.contacts.export@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'admin',
            'active' => true,
        ]);

        Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead Admin',
            'phone_e164' => '+5511900000001',
            'source' => 'google',
            'source_method' => 'auto',
        ]);

        $token = $this->login($admin->email);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get('/api/v1/contacts/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('+5511900000001', $response->streamedContent());
    }

    public function test_sdr_cannot_export_contacts_csv(): void
    {
        $company = Company::create(['name' => 'Empresa SDR Export', 'slug' => 'empresa-sdr-export']);
        $sdr = User::create([
            'company_id' => $company->id,
            'name' => 'SDR Export',
            'email' => 'sdr.contacts.export@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'sdr',
            'active' => true,
        ]);

        $token = $this->login($sdr->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get('/api/v1/contacts/export')
            ->assertForbidden();
    }

    public function test_unauthenticated_user_gets_401_on_contacts_export_endpoint(): void
    {
        $this->get('/api/v1/contacts/export')->assertUnauthorized();
    }

    public function test_contacts_export_respects_filters_and_tenant_isolation(): void
    {
        [$companyA, $gestorA, $columnA] = $this->seedCompanyUserAndColumn('empresa-export-a', 'gestor.contacts.export.a@test.local');
        [$companyB] = $this->seedCompanyUserAndColumn('empresa-export-b', 'gestor.contacts.export.b@test.local');

        $leadAIncluded = Lead::create([
            'company_id' => $companyA->id,
            'name' => 'Lead Included',
            'phone_e164' => '+5511970000001',
            'source' => 'instagram',
            'source_method' => 'manual',
            'is_repeat_lead' => false,
        ]);

        $leadAExcludedByFilter = Lead::create([
            'company_id' => $companyA->id,
            'name' => 'Lead Excluded',
            'phone_e164' => '+5511970000002',
            'source' => 'google',
            'source_method' => 'auto',
            'is_repeat_lead' => false,
        ]);

        $leadOtherTenant = Lead::create([
            'company_id' => $companyB->id,
            'name' => 'Lead Tenant B',
            'phone_e164' => '+5511970000003',
            'source' => 'instagram',
            'source_method' => 'manual',
            'is_repeat_lead' => false,
        ]);

        foreach ([$leadAIncluded, $leadAExcludedByFilter] as $lead) {
            LeadStageHistory::create([
                'company_id' => $companyA->id,
                'lead_id' => $lead->id,
                'from_column_id' => null,
                'to_column_id' => $columnA->id,
                'moved_by_user_id' => $gestorA->id,
                'move_source' => 'manual',
                'reason' => 'Entrada',
                'moved_at' => now()->subHour(),
            ]);
        }

        Message::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadAIncluded->id,
            'conversation_id' => Conversation::create([
                'company_id' => $companyA->id,
                'lead_id' => $leadAIncluded->id,
                'status' => 'active',
                'started_at' => now()->subHours(4),
                'last_message_at' => now()->subHours(2),
            ])->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Msg included',
            'sent_at' => now()->subHours(2),
            'external_message_id' => 'wamid.contacts.export.filter.1',
        ]);

        Message::create([
            'company_id' => $companyA->id,
            'lead_id' => $leadAExcludedByFilter->id,
            'conversation_id' => Conversation::create([
                'company_id' => $companyA->id,
                'lead_id' => $leadAExcludedByFilter->id,
                'status' => 'active',
                'started_at' => now()->subHours(4),
                'last_message_at' => now()->subHours(1),
            ])->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Msg excluded',
            'sent_at' => now()->subHours(1),
            'external_message_id' => 'wamid.contacts.export.filter.2',
        ]);

        Message::create([
            'company_id' => $companyB->id,
            'lead_id' => $leadOtherTenant->id,
            'conversation_id' => Conversation::create([
                'company_id' => $companyB->id,
                'lead_id' => $leadOtherTenant->id,
                'status' => 'active',
                'started_at' => now()->subHours(4),
                'last_message_at' => now()->subHours(2),
            ])->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'Msg tenant B',
            'sent_at' => now()->subHours(2),
            'external_message_id' => 'wamid.contacts.export.filter.3',
        ]);

        $token = $this->login($gestorA->email);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get('/api/v1/contacts/export?source=instagram&last_message_to=' . urlencode(now()->subHours(1)->format('Y-m-d H:i:s')));

        $response->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('+5511970000001', $csv);
        $this->assertStringNotContainsString('+5511970000002', $csv);
        $this->assertStringNotContainsString('+5511970000003', $csv);
    }

    /**
     * @return array{0: Company, 1: User, 2: KanbanColumn}
     */
    private function seedCompanyUserAndColumn(string $slug, string $email): array
    {
        $company = Company::create([
            'name' => 'Empresa ' . strtoupper(substr($slug, -1)),
            'slug' => $slug,
            'timezone' => 'America/Sao_Paulo',
        ]);

        $gestor = User::create([
            'company_id' => $company->id,
            'name' => 'Gestor ' . strtoupper(substr($slug, -1)),
            'email' => $email,
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);

        $pipeline = Pipeline::create([
            'company_id' => $company->id,
            'name' => 'Pipeline Comercial',
            'is_default' => true,
        ]);

        $column = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Novo Contato',
            'position' => 1,
        ]);

        return [$company, $gestor, $column];
    }

    private function login(string $email): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => '12345678',
        ])->json('token');
    }
}
