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
use App\Services\WhatsApp\WhatsAppProviderInterface;
use App\Services\WhatsApp\WhatsAppSendResult;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InboxMessageProviderContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbox_message_service_passes_company_id_in_context_and_persists_only_on_provider_success(): void
    {
        Carbon::setTestNow('2026-05-14 12:00:00');

        $company = Company::create([
            'name' => 'Empresa Contexto',
            'slug' => 'empresa-contexto-provider',
        ]);

        $gestor = User::create([
            'company_id' => $company->id,
            'name' => 'Gestor Contexto',
            'email' => 'gestor.contexto@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);

        $pipeline = Pipeline::create([
            'company_id' => $company->id,
            'name' => 'Pipeline Contexto',
            'is_default' => true,
        ]);

        $column = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Novo Contato',
            'position' => 1,
        ]);

        $lead = Lead::create([
            'company_id' => $company->id,
            'owner_user_id' => null,
            'name' => 'Lead Contexto',
            'phone_e164' => '+5511995001000',
            'source' => 'google',
            'source_method' => 'auto',
            'is_repeat_lead' => false,
        ]);

        $conversation = Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'owner_user_id' => null,
            'status' => 'active',
            'started_at' => now()->subDay(),
            'last_message_at' => now()->subMinutes(4),
        ]);

        LeadStageHistory::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'from_column_id' => null,
            'to_column_id' => $column->id,
            'moved_by_user_id' => null,
            'move_source' => 'system',
            'reason' => 'Entrada inicial',
            'moved_at' => now()->subHours(2),
        ]);

        Message::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'conversation_id' => $conversation->id,
            'provider' => 'whatsapp-cloud',
            'direction' => 'inbound',
            'channel' => 'text',
            'body' => 'Inbound recente',
            'sent_at' => now()->subMinutes(4),
            'external_message_id' => 'wamid.context.inbound',
        ]);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $gestor->email,
            'password' => '12345678',
            'device_name' => 'tests',
        ])->assertOk()->json('token');

        $spyProvider = new class implements WhatsAppProviderInterface {
            /** @var array<string, mixed>|null */
            public ?array $lastContext = null;

            public function sendTextMessage(string $toPhone, string $body, array $context = []): WhatsAppSendResult
            {
                $this->lastContext = $context;

                return WhatsAppSendResult::success(
                    provider: 'fake',
                    externalMessageId: 'fake-context-ok'
                );
            }
        };

        app()->instance(WhatsAppProviderInterface::class, $spyProvider);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/inbox/conversations/' . $conversation->id . '/messages', [
                'body' => 'Mensagem com contexto',
            ])
            ->assertOk()
            ->assertJsonPath('data.provider', 'fake');

        $this->assertIsArray($spyProvider->lastContext);
        $this->assertSame($company->id, (int) ($spyProvider->lastContext['company_id'] ?? 0));

        $this->assertDatabaseHas('messages', [
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'external_message_id' => 'fake-context-ok',
        ]);

        $failureProvider = new class implements WhatsAppProviderInterface {
            public function sendTextMessage(string $toPhone, string $body, array $context = []): WhatsAppSendResult
            {
                return WhatsAppSendResult::failure(
                    provider: 'fake',
                    errorCode: 'provider_fail',
                    errorMessage: 'Falha simulada no provider.'
                );
            }
        };

        app()->instance(WhatsAppProviderInterface::class, $failureProvider);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/inbox/conversations/' . $conversation->id . '/messages', [
                'body' => 'Mensagem deve falhar',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'provider_fail');

        $this->assertDatabaseMissing('messages', [
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'body' => 'Mensagem deve falhar',
        ]);

        Carbon::setTestNow();
    }
}
