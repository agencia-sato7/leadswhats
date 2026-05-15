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
use App\Services\WhatsApp\WhatsAppProviderInterface;
use App\Services\WhatsApp\WhatsAppSendResult;
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
        $this->getJson('/api/v1/inbox/conversations/1/events')->assertUnauthorized();
        $this->postJson('/api/v1/inbox/conversations/1/messages', ['body' => 'oi'])->assertUnauthorized();
    }

    public function test_timeline_is_visible_for_gestor_admin_and_owned_sdr_and_hidden_otherwise(): void
    {
        Carbon::setTestNow('2026-05-14 12:00:00');
        [$companyA, $gestorA, $adminA, $columnA] = $this->seedCompanyWithManagerAndAdmin('empresa-events-a', 'gestor.events.a@test.local', 'admin.events.a@test.local');
        [$companyB, $gestorB] = $this->seedCompanyWithManagerAndAdmin('empresa-events-b', 'gestor.events.b@test.local', 'admin.events.b@test.local');
        $sdrOwner = $this->createUser($companyA->id, 'SDR Owner', 'sdr.owner.events@test.local', 'sdr');
        $sdrOther = $this->createUser($companyA->id, 'SDR Other', 'sdr.other.events@test.local', 'sdr');
        $columnB = $this->seedColumn($companyA->id, 'Em negociação', 2);

        $conversation = $this->seedConversationWithMessages(
            $companyA->id,
            $columnA->id,
            'Lead Timeline',
            '+5511993000001',
            'google',
            'inbound',
            now()->subMinutes(2),
            now()->subMinutes(2),
            $sdrOwner->id,
            $sdrOwner->id,
        );

        LeadStageHistory::create([
            'company_id' => $companyA->id,
            'lead_id' => $conversation->lead_id,
            'from_column_id' => $columnA->id,
            'to_column_id' => $columnB->id,
            'moved_by_user_id' => $sdrOwner->id,
            'move_source' => 'manual',
            'reason' => 'Cliente pediu proposta',
            'moved_at' => now()->subMinutes(15),
        ]);

        $gestorToken = $this->login($gestorA->email);
        $adminToken = $this->login($adminA->email);
        $sdrOwnerToken = $this->login($sdrOwner->email);
        $sdrOtherToken = $this->login($sdrOther->email);
        $otherTenantToken = $this->login($gestorB->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $gestorToken])
            ->getJson('/api/v1/inbox/conversations/' . $conversation->id)
            ->assertOk();

        $this->withHeaders(['Authorization' => 'Bearer ' . $gestorToken])
            ->postJson('/api/v1/inbox/conversations/' . $conversation->id . '/messages', [
                'body' => 'Mensagem timeline',
            ])
            ->assertOk();

        $gestorTimeline = $this->withHeaders(['Authorization' => 'Bearer ' . $gestorToken])
            ->getJson('/api/v1/inbox/conversations/' . $conversation->id . '/events')
            ->assertOk();

        $events = collect($gestorTimeline->json('data'));
        $this->assertTrue($events->contains(fn ($event) => $event['event_type'] === 'conversation_opened'));
        $this->assertTrue($events->contains(fn ($event) => $event['event_type'] === 'message_sent'));
        $this->assertTrue($events->contains(fn ($event) => $event['event_type'] === 'stage_changed'));

        $stageEvent = $events->first(fn ($event) => $event['event_type'] === 'stage_changed' && data_get($event, 'metadata.reason') === 'Cliente pediu proposta');
        $this->assertNotNull($stageEvent);
        $this->assertSame($columnA->id, data_get($stageEvent, 'metadata.from_column_id'));
        $this->assertSame('Novo Contato', data_get($stageEvent, 'metadata.from_column_name'));
        $this->assertSame($columnB->id, data_get($stageEvent, 'metadata.to_column_id'));
        $this->assertSame('Em negociação', data_get($stageEvent, 'metadata.to_column_name'));
        $this->assertSame('manual', data_get($stageEvent, 'metadata.move_source'));
        $this->assertSame('Cliente pediu proposta', data_get($stageEvent, 'metadata.reason'));

        $times = $events->pluck('occurred_at')->values()->all();
        $sortedTimes = $times;
        sort($sortedTimes);
        $this->assertSame($sortedTimes, $times);

        $this->withHeaders(['Authorization' => 'Bearer ' . $adminToken])
            ->getJson('/api/v1/inbox/conversations/' . $conversation->id . '/events')
            ->assertOk();

        $this->withHeaders(['Authorization' => 'Bearer ' . $sdrOwnerToken])
            ->getJson('/api/v1/inbox/conversations/' . $conversation->id . '/events')
            ->assertOk();

        $this->withHeaders(['Authorization' => 'Bearer ' . $sdrOtherToken])
            ->getJson('/api/v1/inbox/conversations/' . $conversation->id . '/events')
            ->assertNotFound();

        $this->withHeaders(['Authorization' => 'Bearer ' . $otherTenantToken])
            ->getJson('/api/v1/inbox/conversations/' . $conversation->id . '/events')
            ->assertNotFound();

        $this->assertDatabaseMissing('conversation_events', [
            'company_id' => $companyB->id,
            'conversation_id' => $conversation->id,
            'event_type' => 'conversation_opened',
        ]);

        Carbon::setTestNow();
    }

    public function test_gestor_and_admin_can_send_message_inside_service_window(): void
    {
        Carbon::setTestNow('2026-05-14 12:00:00');
        [$company, $gestor, $admin, $column] = $this->seedCompanyWithManagerAndAdmin('empresa-send-ga', 'gestor.send.ga@test.local', 'admin.send.ga@test.local');

        $conversation = $this->seedConversationWithMessages(
            $company->id,
            $column->id,
            'Lead Janela Aberta',
            '+5511995000001',
            'google',
            'inbound',
            now()->subMinutes(10),
            now()->subMinutes(10),
        );

        $gestorToken = $this->login($gestor->email);
        $adminToken = $this->login($admin->email);

        $gestorResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $gestorToken])
            ->postJson('/api/v1/inbox/conversations/' . $conversation->id . '/messages', [
                'body' => 'Olá, como posso ajudar?',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Message sent successfully.')
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.provider', 'fake');

        $this->assertNotEmpty($gestorResponse->json('data.external_message_id'));

        $this->withHeaders(['Authorization' => 'Bearer ' . $adminToken])
            ->postJson('/api/v1/inbox/conversations/' . $conversation->id . '/messages', [
                'body' => 'Olá! Retornando seu atendimento.',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Message sent successfully.')
            ->assertJsonPath('data.provider', 'fake');

        $this->assertDatabaseHas('conversation_events', [
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'lead_id' => $conversation->lead_id,
            'user_id' => $gestor->id,
            'event_type' => 'message_sent',
        ]);

        $this->assertDatabaseHas('conversation_events', [
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'lead_id' => $conversation->lead_id,
            'user_id' => $admin->id,
            'event_type' => 'message_sent',
        ]);

        Carbon::setTestNow();
    }

    public function test_sdr_with_ownership_can_send_but_without_ownership_cannot(): void
    {
        Carbon::setTestNow('2026-05-14 12:00:00');
        $company = Company::create(['name' => 'Empresa SDR Send', 'slug' => 'empresa-sdr-send']);
        $sdrA = $this->createUser($company->id, 'SDR A', 'sdr.a.send@test.local', 'sdr');
        $sdrB = $this->createUser($company->id, 'SDR B', 'sdr.b.send@test.local', 'sdr');
        $column = $this->seedDefaultColumn($company->id, 'Pipeline SDR Send');

        $ownedConversation = $this->seedConversationWithMessages(
            $company->id,
            $column->id,
            'Lead SDR A',
            '+5511995000002',
            'google',
            'inbound',
            now()->subMinutes(20),
            now()->subMinutes(20),
            $sdrA->id,
            $sdrA->id,
        );

        $notOwnedConversation = $this->seedConversationWithMessages(
            $company->id,
            $column->id,
            'Lead SDR B',
            '+5511995000003',
            'google',
            'inbound',
            now()->subMinutes(30),
            now()->subMinutes(30),
            $sdrB->id,
            $sdrB->id,
        );

        $tokenA = $this->login($sdrA->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $tokenA])
            ->postJson('/api/v1/inbox/conversations/' . $ownedConversation->id . '/messages', [
                'body' => 'Atendimento SDR com ownership',
            ])
            ->assertOk();

        $this->withHeaders(['Authorization' => 'Bearer ' . $tokenA])
            ->postJson('/api/v1/inbox/conversations/' . $notOwnedConversation->id . '/messages', [
                'body' => 'Tentativa sem ownership',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('conversation_events', [
            'company_id' => $company->id,
            'conversation_id' => $notOwnedConversation->id,
            'user_id' => $sdrA->id,
            'event_type' => 'message_sent',
        ]);

        Carbon::setTestNow();
    }

    public function test_cross_tenant_send_is_blocked_with_404(): void
    {
        Carbon::setTestNow('2026-05-14 12:00:00');
        [$companyA, $gestorA, $adminA, $columnA] = $this->seedCompanyWithManagerAndAdmin('empresa-send-tenant-a', 'gestor.send.tenant.a@test.local', 'admin.send.tenant.a@test.local');
        [$companyB, $gestorB] = $this->seedCompanyWithManagerAndAdmin('empresa-send-tenant-b', 'gestor.send.tenant.b@test.local', 'admin.send.tenant.b@test.local');

        $conversationA = $this->seedConversationWithMessages(
            $companyA->id,
            $columnA->id,
            'Lead Tenant A',
            '+5511995000004',
            'google',
            'inbound',
            now()->subMinutes(15),
            now()->subMinutes(15),
        );

        $tokenB = $this->login($gestorB->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $tokenB])
            ->postJson('/api/v1/inbox/conversations/' . $conversationA->id . '/messages', [
                'body' => 'Cross tenant',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('messages', [
            'company_id' => $companyB->id,
            'conversation_id' => $conversationA->id,
            'direction' => 'outbound',
            'body' => 'Cross tenant',
        ]);

        Carbon::setTestNow();
    }

    public function test_send_message_validates_body_and_service_window(): void
    {
        Carbon::setTestNow('2026-05-14 12:00:00');
        [$company, $gestor, $admin, $column] = $this->seedCompanyWithManagerAndAdmin('empresa-send-validation', 'gestor.send.validation@test.local', 'admin.send.validation@test.local');

        $closedConversation = $this->seedConversationWithMessages(
            $company->id,
            $column->id,
            'Lead Janela Fechada',
            '+5511995000005',
            'google',
            'inbound',
            now()->subHours(30),
            now()->subHours(30),
        );

        $token = $this->login($gestor->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/inbox/conversations/' . $closedConversation->id . '/messages', [
                'body' => '',
            ])
            ->assertStatus(422);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/inbox/conversations/' . $closedConversation->id . '/messages', [
                'body' => 'Mensagem fora da janela',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Service window is closed. Wait for a recent inbound message before sending.');

        $this->assertDatabaseMissing('messages', [
            'company_id' => $company->id,
            'conversation_id' => $closedConversation->id,
            'direction' => 'outbound',
            'body' => 'Mensagem fora da janela',
        ]);

        Carbon::setTestNow();
    }

    public function test_provider_failure_does_not_create_outbound_message_and_detail_includes_sent_message_on_success(): void
    {
        Carbon::setTestNow('2026-05-14 12:00:00');
        [$company, $gestor, $admin, $column] = $this->seedCompanyWithManagerAndAdmin('empresa-send-provider', 'gestor.send.provider@test.local', 'admin.send.provider@test.local');

        $conversation = $this->seedConversationWithMessages(
            $company->id,
            $column->id,
            'Lead Provider',
            '+5511995000006',
            'google',
            'inbound',
            now()->subMinutes(5),
            now()->subMinutes(5),
        );

        $token = $this->login($gestor->email);

        app()->bind(WhatsAppProviderInterface::class, static fn () => new class implements WhatsAppProviderInterface {
            public function sendTextMessage(string $toPhone, string $body, array $context = []): WhatsAppSendResult
            {
                return WhatsAppSendResult::failure(
                    provider: 'fake',
                    errorCode: 'provider_down',
                    errorMessage: 'Provider failed (simulated).'
                );
            }
        });

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/inbox/conversations/' . $conversation->id . '/messages', [
                'body' => 'Mensagem com falha simulada',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Provider failed (simulated).')
            ->assertJsonPath('provider', 'fake')
            ->assertJsonPath('error_code', 'provider_down');

        $this->assertDatabaseMissing('messages', [
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'Mensagem com falha simulada',
        ]);

        app()->bind(WhatsAppProviderInterface::class, \App\Services\WhatsApp\FakeWhatsAppProvider::class);

        $sendSuccess = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/inbox/conversations/' . $conversation->id . '/messages', [
                'body' => 'Mensagem enviada com sucesso',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Message sent successfully.')
            ->assertJsonPath('data.provider', 'fake');

        $sentMessageId = $sendSuccess->json('data.id');

        $detail = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/inbox/conversations/' . $conversation->id)
            ->assertOk();

        $detailMessages = collect($detail->json('data.messages'));
        $this->assertTrue($detailMessages->contains(fn ($item) => (int) $item['id'] === (int) $sentMessageId));

        Carbon::setTestNow();
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
