<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LeadOwnerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_gets_401_on_owner_assignment_endpoint(): void
    {
        $this->patchJson('/api/v1/leads/1/owner', [
            'owner_user_id' => 1,
            'reason' => 'Distribuição manual pelo gestor',
        ])->assertUnauthorized();
    }

    public function test_gestor_can_assign_owner_and_inbox_reflects_update(): void
    {
        $company = $this->createCompany('empresa-owner-gestor');
        $gestor = $this->createUser($company->id, 'Gestor', 'gestor.owner@test.local', 'gestor');
        $sdr = $this->createUser($company->id, 'SDR A', 'sdr.owner@test.local', 'sdr');
        [$lead, $conversation] = $this->seedLeadWithConversation($company->id, 'Lead Owner Gestor', '+5511911111001');

        $token = $this->login($gestor->email);
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson('/api/v1/leads/' . $lead->id . '/owner', [
                'owner_user_id' => $sdr->id,
                'reason' => 'Distribuição manual pelo gestor',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Lead owner updated successfully.')
            ->assertJsonPath('data.lead_id', $lead->id)
            ->assertJsonPath('data.owner_user_id', $sdr->id)
            ->assertJsonPath('data.owner_name', 'SDR A')
            ->assertJsonPath('data.updated_by_user_id', $gestor->id);

        $this->assertSame($sdr->id, $response->json('data.owner_user_id'));

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'company_id' => $company->id,
            'owner_user_id' => $sdr->id,
        ]);

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'company_id' => $company->id,
            'owner_user_id' => $sdr->id,
        ]);

        $this->assertDatabaseHas('conversation_events', [
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'lead_id' => $lead->id,
            'user_id' => $gestor->id,
            'event_type' => 'owner_assigned',
        ]);

        $event = ConversationEvent::query()
            ->where('company_id', $company->id)
            ->where('conversation_id', $conversation->id)
            ->where('event_type', 'owner_assigned')
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame([
            'previous_owner_user_id' => null,
            'previous_owner_name' => null,
            'new_owner_user_id' => $sdr->id,
            'new_owner_name' => 'SDR A',
            'reason' => 'Distribuição manual pelo gestor',
        ], $event->metadata);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.owner_user_id', $sdr->id)
            ->assertJsonPath('data.0.owner_name', 'SDR A');

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations/' . $conversation->id)
            ->assertOk()
            ->assertJsonPath('data.owner.owner_user_id', $sdr->id)
            ->assertJsonPath('data.owner.owner_name', 'SDR A');
    }

    public function test_admin_can_assign_owner_successfully(): void
    {
        $company = $this->createCompany('empresa-owner-admin');
        $admin = $this->createUser($company->id, 'Admin', 'admin.owner@test.local', 'admin');
        $sdr = $this->createUser($company->id, 'SDR B', 'sdr.b.owner@test.local', 'sdr');
        [$lead] = $this->seedLeadWithConversation($company->id, 'Lead Owner Admin', '+5511911111002');

        $token = $this->login($admin->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson('/api/v1/leads/' . $lead->id . '/owner', [
                'owner_user_id' => $sdr->id,
                'reason' => 'Distribuição manual pelo admin',
            ])
            ->assertOk()
            ->assertJsonPath('data.owner_user_id', $sdr->id)
            ->assertJsonPath('data.owner_name', 'SDR B')
            ->assertJsonPath('data.updated_by_user_id', $admin->id);
    }

    public function test_sdr_gets_403_when_trying_to_assign_owner(): void
    {
        $company = $this->createCompany('empresa-owner-sdr');
        $sdr = $this->createUser($company->id, 'SDR', 'sdr.owner.forbidden@test.local', 'sdr');
        $anotherSdr = $this->createUser($company->id, 'SDR Outro', 'sdr.owner.other@test.local', 'sdr');
        [$lead] = $this->seedLeadWithConversation($company->id, 'Lead Owner SDR', '+5511911111003');

        $token = $this->login($sdr->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson('/api/v1/leads/' . $lead->id . '/owner', [
                'owner_user_id' => $anotherSdr->id,
                'reason' => 'Tentativa de distribuição pelo SDR',
            ])
            ->assertForbidden();
    }

    public function test_cross_tenant_lead_returns_404(): void
    {
        $companyA = $this->createCompany('empresa-owner-tenant-a');
        $companyB = $this->createCompany('empresa-owner-tenant-b');
        $gestorA = $this->createUser($companyA->id, 'Gestor A', 'gestor.owner.a@test.local', 'gestor');
        $sdrA = $this->createUser($companyA->id, 'SDR A', 'sdr.owner.a@test.local', 'sdr');
        [$leadB] = $this->seedLeadWithConversation($companyB->id, 'Lead Tenant B', '+5511911111004');

        $token = $this->login($gestorA->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson('/api/v1/leads/' . $leadB->id . '/owner', [
                'owner_user_id' => $sdrA->id,
                'reason' => 'Tentativa cross-tenant',
            ])
            ->assertNotFound();
    }

    public function test_owner_from_another_company_returns_422(): void
    {
        $companyA = $this->createCompany('empresa-owner-validation-a');
        $companyB = $this->createCompany('empresa-owner-validation-b');
        $gestorA = $this->createUser($companyA->id, 'Gestor A', 'gestor.owner.validation.a@test.local', 'gestor');
        $externalSdr = $this->createUser($companyB->id, 'SDR Externo', 'sdr.owner.validation.b@test.local', 'sdr');
        [$leadA] = $this->seedLeadWithConversation($companyA->id, 'Lead Validation A', '+5511911111005');

        $token = $this->login($gestorA->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson('/api/v1/leads/' . $leadA->id . '/owner', [
                'owner_user_id' => $externalSdr->id,
                'reason' => 'Tentativa com owner externo',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['owner_user_id']);
    }

    public function test_owner_user_id_null_removes_owner_and_creates_owner_removed_event(): void
    {
        $company = $this->createCompany('empresa-owner-remove');
        $gestor = $this->createUser($company->id, 'Gestor', 'gestor.owner.remove@test.local', 'gestor');
        $previousOwner = $this->createUser($company->id, 'SDR Remove', 'sdr.owner.remove@test.local', 'sdr');
        [$lead, $conversation] = $this->seedLeadWithConversation(
            $company->id,
            'Lead Owner Remove',
            '+5511911111006',
            $previousOwner->id,
            $previousOwner->id,
        );

        $token = $this->login($gestor->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson('/api/v1/leads/' . $lead->id . '/owner', [
                'owner_user_id' => null,
                'reason' => 'Removido responsável',
            ])
            ->assertOk()
            ->assertJsonPath('data.owner_user_id', null)
            ->assertJsonPath('data.owner_name', null);

        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'owner_user_id' => null,
        ]);

        $this->assertDatabaseHas('conversations', [
            'id' => $conversation->id,
            'owner_user_id' => null,
        ]);

        $event = ConversationEvent::query()
            ->where('company_id', $company->id)
            ->where('conversation_id', $conversation->id)
            ->where('event_type', 'owner_removed')
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($previousOwner->id, data_get($event->metadata, 'previous_owner_user_id'));
        $this->assertSame('SDR Remove', data_get($event->metadata, 'previous_owner_name'));
        $this->assertNull(data_get($event->metadata, 'new_owner_user_id'));
        $this->assertNull(data_get($event->metadata, 'new_owner_name'));
        $this->assertSame('Removido responsável', data_get($event->metadata, 'reason'));
    }

    public function test_changing_owner_creates_owner_changed_event(): void
    {
        $company = $this->createCompany('empresa-owner-change');
        $gestor = $this->createUser($company->id, 'Gestor', 'gestor.owner.change@test.local', 'gestor');
        $ownerA = $this->createUser($company->id, 'SDR A', 'sdr.owner.change.a@test.local', 'sdr');
        $ownerB = $this->createUser($company->id, 'SDR B', 'sdr.owner.change.b@test.local', 'sdr');
        [$lead, $conversation] = $this->seedLeadWithConversation(
            $company->id,
            'Lead Owner Change',
            '+5511911111007',
            $ownerA->id,
            $ownerA->id,
        );

        $token = $this->login($gestor->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson('/api/v1/leads/' . $lead->id . '/owner', [
                'owner_user_id' => $ownerB->id,
                'reason' => 'Redistribuição de carteira',
            ])
            ->assertOk()
            ->assertJsonPath('data.owner_user_id', $ownerB->id)
            ->assertJsonPath('data.owner_name', 'SDR B');

        $event = ConversationEvent::query()
            ->where('company_id', $company->id)
            ->where('conversation_id', $conversation->id)
            ->where('event_type', 'owner_changed')
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame($ownerA->id, data_get($event->metadata, 'previous_owner_user_id'));
        $this->assertSame('SDR A', data_get($event->metadata, 'previous_owner_name'));
        $this->assertSame($ownerB->id, data_get($event->metadata, 'new_owner_user_id'));
        $this->assertSame('SDR B', data_get($event->metadata, 'new_owner_name'));
        $this->assertSame('Redistribuição de carteira', data_get($event->metadata, 'reason'));
    }

    public function test_updates_all_active_conversations_and_registers_event_on_most_recent_active(): void
    {
        $company = $this->createCompany('empresa-owner-multi-conv');
        $gestor = $this->createUser($company->id, 'Gestor', 'gestor.owner.multi@test.local', 'gestor');
        $owner = $this->createUser($company->id, 'SDR Multi', 'sdr.owner.multi@test.local', 'sdr');

        $lead = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead Multi Conv',
            'phone_e164' => '+5511911111008',
            'source' => 'google',
            'source_method' => 'manual',
        ]);

        $olderActive = Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'owner_user_id' => null,
            'status' => 'active',
            'started_at' => now()->subDays(1),
            'last_message_at' => now()->subHours(3),
        ]);

        $mostRecentActive = Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'owner_user_id' => null,
            'status' => 'active',
            'started_at' => now()->subHours(5),
            'last_message_at' => now()->subMinutes(10),
        ]);

        Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'owner_user_id' => null,
            'status' => 'closed',
            'started_at' => now()->subDays(2),
            'last_message_at' => now()->subDays(1),
            'closed_at' => now()->subHours(1),
        ]);

        $token = $this->login($gestor->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson('/api/v1/leads/' . $lead->id . '/owner', [
                'owner_user_id' => $owner->id,
                'reason' => 'Distribuição para multi-conversas',
            ])
            ->assertOk();

        $this->assertDatabaseHas('conversations', [
            'id' => $olderActive->id,
            'owner_user_id' => $owner->id,
        ]);

        $this->assertDatabaseHas('conversations', [
            'id' => $mostRecentActive->id,
            'owner_user_id' => $owner->id,
        ]);

        $this->assertDatabaseHas('conversation_events', [
            'company_id' => $company->id,
            'conversation_id' => $mostRecentActive->id,
            'lead_id' => $lead->id,
            'user_id' => $gestor->id,
            'event_type' => 'owner_assigned',
        ]);

        $this->assertDatabaseMissing('conversation_events', [
            'company_id' => $company->id,
            'conversation_id' => $olderActive->id,
            'lead_id' => $lead->id,
            'user_id' => $gestor->id,
            'event_type' => 'owner_assigned',
        ]);
    }

    /**
     * @return array{0: Lead, 1: Conversation}
     */
    private function seedLeadWithConversation(
        int $companyId,
        string $leadName,
        string $phone,
        ?int $leadOwnerUserId = null,
        ?int $conversationOwnerUserId = null,
    ): array {
        $lead = Lead::create([
            'company_id' => $companyId,
            'owner_user_id' => $leadOwnerUserId,
            'name' => $leadName,
            'phone_e164' => $phone,
            'source' => 'google',
            'source_method' => 'manual',
        ]);

        $conversation = Conversation::create([
            'company_id' => $companyId,
            'lead_id' => $lead->id,
            'owner_user_id' => $conversationOwnerUserId,
            'status' => 'active',
            'started_at' => now()->subHours(2),
            'last_message_at' => now()->subMinutes(5),
        ]);

        Message::create([
            'company_id' => $companyId,
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'body' => 'Oi',
            'sent_at' => now()->subMinutes(5),
        ]);

        return [$lead, $conversation];
    }

    private function createCompany(string $slug): Company
    {
        return Company::create([
            'name' => 'Empresa ' . $slug,
            'slug' => $slug,
            'timezone' => 'America/Sao_Paulo',
        ]);
    }

    private function createUser(int $companyId, string $name, string $email, string $role): User
    {
        return User::create([
            'company_id' => $companyId,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('12345678'),
            'role' => $role,
            'active' => true,
        ]);
    }

    private function login(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => '12345678',
        ])->json('token');
    }
}

