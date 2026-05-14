<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationEvent;
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

class InboxApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_gets_401_on_inbox_endpoints(): void
    {
        $this->getJson('/api/v1/inbox/conversations')->assertUnauthorized();
        $this->getJson('/api/v1/inbox/conversations/1')->assertUnauthorized();
    }

    public function test_gestor_and_admin_list_company_conversations_only(): void
    {
        Carbon::setTestNow('2026-05-14 12:00:00');
        [$companyA, $gestorA, $adminA, $columnA] = $this->seedCompanyWithManagerAndAdmin('empresa-inbox-a', 'gestor.inbox.a@test.local', 'admin.inbox.a@test.local');
        [$companyB] = $this->seedCompanyWithManagerAndAdmin('empresa-inbox-b', 'gestor.inbox.b@test.local', 'admin.inbox.b@test.local');

        $convA = $this->seedConversationWithMessages($companyA->id, $columnA->id, 'Maria', '+5511991111111', 'instagram', 'outbound', now()->subHours(1), now()->subHours(2));
        $this->seedConversationWithMessages($companyB->id, null, 'Outro Tenant', '+5511988888888', 'google', 'outbound', now()->subHours(1), now()->subHours(2));

        $gestorToken = $this->login($gestorA->email);
        $adminToken = $this->login($adminA->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $gestorToken])
            ->getJson('/api/v1/inbox/conversations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.conversation_id', $convA->id);

        $this->withHeaders(['Authorization' => 'Bearer ' . $adminToken])
            ->getJson('/api/v1/inbox/conversations')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.conversation_id', $convA->id);

        Carbon::setTestNow();
    }

    public function test_sdr_only_sees_owned_conversations_and_not_unowned(): void
    {
        Carbon::setTestNow('2026-05-14 12:00:00');
        $company = Company::create(['name' => 'Empresa Inbox', 'slug' => 'empresa-inbox-sdr']);
        $sdrA = $this->createUser($company->id, 'SDR A', 'sdr.a.inbox@test.local', 'sdr');
        $sdrB = $this->createUser($company->id, 'SDR B', 'sdr.b.inbox@test.local', 'sdr');
        $column = $this->seedDefaultColumn($company->id, 'Pipeline SDR');

        $convByConversationOwner = $this->seedConversationWithMessages($company->id, $column->id, 'Lead Conv Owner', '+5511911110001', 'google', 'outbound', now()->subHours(1), now()->subHours(2), null, $sdrA->id);
        $convByLeadOwner = $this->seedConversationWithMessages($company->id, $column->id, 'Lead Lead Owner', '+5511911110002', 'google', 'outbound', now()->subHours(1), now()->subHours(2), $sdrA->id, null);
        $this->seedConversationWithMessages($company->id, $column->id, 'Lead Outro SDR', '+5511911110003', 'google', 'outbound', now()->subHours(1), now()->subHours(2), $sdrB->id, $sdrB->id);
        $convWithoutOwner = $this->seedConversationWithMessages($company->id, $column->id, 'Lead Sem Dono', '+5511911110004', 'google', 'outbound', now()->subHours(1), now()->subHours(2), null, null);

        $token = $this->login($sdrA->email);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $ids = collect($response->json('data'))->pluck('conversation_id')->all();
        $this->assertContains($convByConversationOwner->id, $ids);
        $this->assertContains($convByLeadOwner->id, $ids);
        $this->assertNotContains($convWithoutOwner->id, $ids);

        Carbon::setTestNow();
    }

    public function test_detail_returns_messages_ordered_by_sent_at_and_cross_tenant_is_404(): void
    {
        Carbon::setTestNow('2026-05-14 12:00:00');
        [$companyA, $gestorA, $adminA, $columnA] = $this->seedCompanyWithManagerAndAdmin('empresa-detail-a', 'gestor.detail.a@test.local', 'admin.detail.a@test.local');
        [$companyB, $gestorB] = $this->seedCompanyWithManagerAndAdmin('empresa-detail-b', 'gestor.detail.b@test.local', 'admin.detail.b@test.local');

        $conversation = $this->seedConversationWithMessages($companyA->id, $columnA->id, 'Lead Detail', '+5511990000001', 'instagram', 'outbound', now()->subHours(1), now()->subHours(2));

        Message::create([
            'company_id' => $companyA->id,
            'lead_id' => $conversation->lead_id,
            'conversation_id' => $conversation->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'inbound',
            'channel' => 'text',
            'body' => 'primeira',
            'sent_at' => now()->subHours(5),
            'external_message_id' => 'wamid.inbox.detail.1',
        ]);
        Message::create([
            'company_id' => $companyA->id,
            'lead_id' => $conversation->lead_id,
            'conversation_id' => $conversation->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'outbound',
            'channel' => 'text',
            'body' => 'segunda',
            'sent_at' => now()->subHours(3),
            'external_message_id' => 'wamid.inbox.detail.2',
        ]);
        Message::create([
            'company_id' => $companyA->id,
            'lead_id' => $conversation->lead_id,
            'conversation_id' => $conversation->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'inbound',
            'channel' => 'text',
            'body' => 'terceira',
            'sent_at' => now()->subHours(1),
            'external_message_id' => 'wamid.inbox.detail.3',
        ]);

        $gestorToken = $this->login($gestorA->email);
        $otherTenantToken = $this->login($gestorB->email);

        $detail = $this->withHeaders(['Authorization' => 'Bearer ' . $gestorToken])
            ->getJson('/api/v1/inbox/conversations/' . $conversation->id)
            ->assertOk();

        $this->assertDatabaseHas('conversation_events', [
            'company_id' => $companyA->id,
            'conversation_id' => $conversation->id,
            'lead_id' => $conversation->lead_id,
            'user_id' => $gestorA->id,
            'event_type' => 'conversation_opened',
        ]);

        $messages = collect($detail->json('data.messages'));
        $this->assertGreaterThanOrEqual(3, $messages->count());
        $sorted = $messages->pluck('sent_at')->values()->all();
        $sortedCopy = $sorted;
        sort($sortedCopy);
        $this->assertSame($sortedCopy, $sorted);

        $this->withHeaders(['Authorization' => 'Bearer ' . $otherTenantToken])
            ->getJson('/api/v1/inbox/conversations/' . $conversation->id)
            ->assertNotFound();

        $this->assertDatabaseMissing('conversation_events', [
            'company_id' => $companyB->id,
            'conversation_id' => $conversation->id,
            'user_id' => $gestorB->id,
            'event_type' => 'conversation_opened',
        ]);

        Carbon::setTestNow();
    }

    public function test_sdr_without_ownership_cannot_open_detail_and_does_not_register_event(): void
    {
        Carbon::setTestNow('2026-05-14 12:00:00');
        $company = Company::create(['name' => 'Empresa SDR Detail', 'slug' => 'empresa-sdr-detail']);
        $sdrA = $this->createUser($company->id, 'SDR A', 'sdr.a.detail@test.local', 'sdr');
        $sdrB = $this->createUser($company->id, 'SDR B', 'sdr.b.detail@test.local', 'sdr');
        $column = $this->seedDefaultColumn($company->id, 'Pipeline SDR Detail');

        $conversation = $this->seedConversationWithMessages(
            $company->id,
            $column->id,
            'Lead dono B',
            '+5511980000001',
            'google',
            'outbound',
            now()->subHour(),
            now()->subHours(2),
            $sdrB->id,
            $sdrB->id,
        );

        $tokenA = $this->login($sdrA->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $tokenA])
            ->getJson('/api/v1/inbox/conversations/' . $conversation->id)
            ->assertNotFound();

        $this->assertDatabaseMissing('conversation_events', [
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'user_id' => $sdrA->id,
            'event_type' => 'conversation_opened',
        ]);

        Carbon::setTestNow();
    }

    public function test_repeated_open_in_short_window_is_deduplicated(): void
    {
        Carbon::setTestNow('2026-05-14 12:00:00');
        [$company, $gestor, $admin, $column] = $this->seedCompanyWithManagerAndAdmin('empresa-dedup', 'gestor.dedup@test.local', 'admin.dedup@test.local');

        $conversation = $this->seedConversationWithMessages(
            $company->id,
            $column->id,
            'Lead Dedup',
            '+5511970000001',
            'google',
            'outbound',
            now()->subMinutes(10),
            now()->subHours(2),
        );

        $token = $this->login($gestor->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations/' . $conversation->id)
            ->assertOk();

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations/' . $conversation->id)
            ->assertOk();

        $countAfterImmediateOpen = ConversationEvent::query()
            ->where('company_id', $company->id)
            ->where('conversation_id', $conversation->id)
            ->where('lead_id', $conversation->lead_id)
            ->where('user_id', $gestor->id)
            ->where('event_type', 'conversation_opened')
            ->count();

        $this->assertSame(1, $countAfterImmediateOpen);

        Carbon::setTestNow(now()->addSeconds(11));

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations/' . $conversation->id)
            ->assertOk();

        $countAfterWindow = ConversationEvent::query()
            ->where('company_id', $company->id)
            ->where('conversation_id', $conversation->id)
            ->where('lead_id', $conversation->lead_id)
            ->where('user_id', $gestor->id)
            ->where('event_type', 'conversation_opened')
            ->count();

        $this->assertSame(2, $countAfterWindow);

        Carbon::setTestNow();
    }

    public function test_service_window_open_true_and_false_cases(): void
    {
        Carbon::setTestNow('2026-05-14 12:00:00');
        [$company, $gestor, $admin, $column] = $this->seedCompanyWithManagerAndAdmin('empresa-window', 'gestor.window@test.local', 'admin.window@test.local');

        $openConversation = $this->seedConversationWithMessages($company->id, $column->id, 'Lead Janela Aberta', '+5511997000001', 'google', 'inbound', now()->subHours(2), now()->subHours(2));
        $closedConversation = $this->seedConversationWithMessages($company->id, $column->id, 'Lead Janela Fechada', '+5511997000002', 'google', 'inbound', now()->subHours(30), now()->subHours(30));

        $token = $this->login($gestor->email);

        $all = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations')
            ->assertOk();

        $items = collect($all->json('data'))->keyBy('conversation_id');
        $this->assertTrue($items[$openConversation->id]['service_window_open']);
        $this->assertFalse($items[$closedConversation->id]['service_window_open']);
        $this->assertNotNull($items[$openConversation->id]['service_window_expires_at']);

        Carbon::setTestNow();
    }

    public function test_conversation_list_filters_and_pagination(): void
    {
        Carbon::setTestNow('2026-05-14 12:00:00');
        [$company, $gestor, $admin, $columnA] = $this->seedCompanyWithManagerAndAdmin('empresa-filters', 'gestor.filters@test.local', 'admin.filters@test.local');
        $columnB = $this->seedColumn($company->id, 'Qualificação', 2);

        $ownerA = $this->createUser($company->id, 'Owner A', 'owner.a@test.local', 'sdr');
        $ownerB = $this->createUser($company->id, 'Owner B', 'owner.b@test.local', 'sdr');

        $conv1 = $this->seedConversationWithMessages($company->id, $columnA->id, 'Maria Teste', '+5511981000001', 'instagram', 'outbound', now()->subHours(26), now()->subHours(3), null, $ownerA->id);
        $conv2 = $this->seedConversationWithMessages($company->id, $columnB->id, 'Joao Teste', '+5511981000002', 'google', 'inbound', now()->subHours(2), now()->subHours(2), null, $ownerB->id);
        $conv3 = $this->seedConversationWithMessages($company->id, $columnB->id, 'Carla Teste', '+5511981000003', 'facebook', 'outbound', now()->subHours(1), now()->subHours(1), null, $ownerB->id);

        $token = $this->login($gestor->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations?search=Maria')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.conversation_id', $conv1->id);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations?owner_user_id=' . $ownerB->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations?source=google')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.conversation_id', $conv2->id);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations?stage_id=' . $columnA->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.conversation_id', $conv1->id);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations?service_window_open=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations?has_open_task=true')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.conversation_id', $conv1->id)
            ->assertJsonPath('data.0.has_open_task', true);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonCount(1, 'data');

        Carbon::setTestNow();
    }

    /**
     * @return array{0: Company, 1: User, 2: User, 3: KanbanColumn}
     */
    private function seedCompanyWithManagerAndAdmin(string $slug, string $gestorEmail, string $adminEmail): array
    {
        $company = Company::create([
            'name' => 'Empresa ' . $slug,
            'slug' => $slug,
        ]);

        $gestor = $this->createUser($company->id, 'Gestor ' . $slug, $gestorEmail, 'gestor');
        $admin = $this->createUser($company->id, 'Admin ' . $slug, $adminEmail, 'admin');
        $column = $this->seedDefaultColumn($company->id, 'Pipeline ' . $slug);

        return [$company, $gestor, $admin, $column];
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
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => '12345678',
            'device_name' => 'tests',
        ])->assertOk();

        return (string) $response->json('token');
    }

    private function seedDefaultColumn(int $companyId, string $pipelineName): KanbanColumn
    {
        $pipeline = Pipeline::create([
            'company_id' => $companyId,
            'name' => $pipelineName,
            'is_default' => true,
        ]);

        return KanbanColumn::create([
            'company_id' => $companyId,
            'pipeline_id' => $pipeline->id,
            'name' => 'Novo Contato',
            'position' => 1,
        ]);
    }

    private function seedColumn(int $companyId, string $name, int $position): KanbanColumn
    {
        $pipeline = Pipeline::query()->where('company_id', $companyId)->firstOrFail();

        return KanbanColumn::create([
            'company_id' => $companyId,
            'pipeline_id' => $pipeline->id,
            'name' => $name,
            'position' => $position,
        ]);
    }

    private function seedConversationWithMessages(
        int $companyId,
        ?int $columnId,
        string $leadName,
        string $phone,
        string $source,
        string $lastDirection,
        Carbon $lastSentAt,
        ?Carbon $lastInboundAt = null,
        ?int $leadOwnerId = null,
        ?int $conversationOwnerId = null,
    ): Conversation {
        $lead = Lead::create([
            'company_id' => $companyId,
            'owner_user_id' => $leadOwnerId,
            'name' => $leadName,
            'phone_e164' => $phone,
            'source' => $source,
            'source_method' => 'auto',
            'is_repeat_lead' => false,
        ]);

        $conversation = Conversation::create([
            'company_id' => $companyId,
            'lead_id' => $lead->id,
            'owner_user_id' => $conversationOwnerId,
            'status' => 'active',
            'started_at' => now()->subDays(1),
            'last_message_at' => $lastSentAt,
        ]);

        if ($columnId) {
            LeadStageHistory::create([
                'company_id' => $companyId,
                'lead_id' => $lead->id,
                'from_column_id' => null,
                'to_column_id' => $columnId,
                'moved_by_user_id' => null,
                'move_source' => 'system',
                'reason' => 'Entrada inicial',
                'moved_at' => now()->subHours(20),
            ]);
        }

        if ($lastInboundAt) {
            Message::create([
                'company_id' => $companyId,
                'lead_id' => $lead->id,
                'conversation_id' => $conversation->id,
                'provider' => 'whatsapp-cloud',
                'direction' => 'inbound',
                'channel' => 'text',
                'body' => 'Mensagem inbound',
                'sent_at' => $lastInboundAt,
                'external_message_id' => uniqid('wamid.inbound.', true),
            ]);
        }

        Message::create([
            'company_id' => $companyId,
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'provider' => 'whatsapp-cloud',
            'direction' => $lastDirection,
            'channel' => 'text',
            'body' => 'Última mensagem',
            'sent_at' => $lastSentAt,
            'external_message_id' => uniqid('wamid.last.', true),
        ]);

        return $conversation;
    }
}
