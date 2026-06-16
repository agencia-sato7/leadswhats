<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiKanbanMovementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear keys to avoid accidental external calls
        putenv('GEMINI_API_KEY=');
        putenv('OPENAI_API_KEY=');
    }

    protected function tearDown(): void
    {
        putenv('GEMINI_API_KEY=');
        putenv('OPENAI_API_KEY=');
        parent::tearDown();
    }

    public function test_webhook_triggers_ai_kanban_movement_via_fallback_keywords_spouse(): void
    {
        $company = Company::create(['name' => 'Empresa AI', 'slug' => 'empresa-ai']);
        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'webhook_token' => 'ai_test_token',
        ]);

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

        $spouseColumn = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Pensar / Cônjuge',
            'position' => 2,
            'rule_prompt' => 'Cliente precisa de tempo para pensar ou falar com a esposa/marido',
        ]);

        $payload = [
            'company_slug' => $company->slug,
            'phone' => '(11) 99999-1234',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'channel' => 'text',
            'body' => 'Gostei muito, mas preciso conversar com a minha esposa primeiro antes de decidir.',
            'source' => 'whatsapp',
            'external_message_id' => 'wamid.ai.test.1',
            'sent_at' => now()->toISOString(),
        ];

        $this->postJson('/api/v1/webhooks/whatsapp', $payload, ['X-Webhook-Token' => 'ai_test_token'])->assertOk();

        $lead = Lead::query()
            ->where('company_id', $company->id)
            ->where('phone_e164', '+5511999991234')
            ->firstOrFail();

        // Should have 2 histories: 1 system initial placement, 1 AI movement
        $histories = LeadStageHistory::query()
            ->where('company_id', $company->id)
            ->where('lead_id', $lead->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $histories);

        $initialHistory = $histories[0];
        $this->assertSame($initialColumn->id, $initialHistory->to_column_id);
        $this->assertSame('system', $initialHistory->move_source);

        $aiHistory = $histories[1];
        $this->assertSame($spouseColumn->id, $aiHistory->to_column_id);
        $this->assertSame($initialColumn->id, $aiHistory->from_column_id);
        $this->assertSame('ai', $aiHistory->move_source);
        $this->assertNull($aiHistory->moved_by_user_id);
        $this->assertStringContainsString('esposa', $aiHistory->reason);
    }

    public function test_webhook_triggers_ai_kanban_movement_via_fallback_keywords_quote(): void
    {
        $company = Company::create(['name' => 'Empresa AI', 'slug' => 'empresa-ai']);
        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'webhook_token' => 'ai_test_token',
        ]);

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

        $quoteColumn = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Orçamento Enviado',
            'position' => 2,
            'rule_prompt' => 'Cliente pediu um orçamento ou informações de preço/valores',
        ]);

        $payload = [
            'company_slug' => $company->slug,
            'phone' => '(11) 99999-5678',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'channel' => 'text',
            'body' => 'Olá, gostaria de saber os valores e pedir um orçamento por favor.',
            'source' => 'whatsapp',
            'external_message_id' => 'wamid.ai.test.2',
            'sent_at' => now()->toISOString(),
        ];

        $this->postJson('/api/v1/webhooks/whatsapp', $payload, ['X-Webhook-Token' => 'ai_test_token'])->assertOk();

        $lead = Lead::query()
            ->where('company_id', $company->id)
            ->where('phone_e164', '+5511999995678')
            ->firstOrFail();

        $latestHistory = LeadStageHistory::query()
            ->where('company_id', $company->id)
            ->where('lead_id', $lead->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame($quoteColumn->id, $latestHistory->to_column_id);
        $this->assertSame('ai', $latestHistory->move_source);
        $this->assertNull($latestHistory->moved_by_user_id);
    }

    public function test_webhook_triggers_ai_kanban_movement_via_llm_openai(): void
    {
        putenv('OPENAI_API_KEY=fake-openai-key-for-test');

        $company = Company::create(['name' => 'Empresa AI', 'slug' => 'empresa-ai']);
        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'webhook_token' => 'ai_test_token',
        ]);

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

        $targetColumn = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Orçamento Enviado',
            'position' => 2,
            'rule_prompt' => 'Cliente pediu um orçamento',
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'matched_column_id' => $targetColumn->id,
                                'reason' => 'Identificado via OpenAI que o cliente solicitou orçamento.'
                            ])
                        ]
                    ]
                ]
            ], 200)
        ]);

        $payload = [
            'company_slug' => $company->slug,
            'phone' => '(11) 99999-0000',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'channel' => 'text',
            'body' => 'Quero saber sobre preços.',
            'source' => 'whatsapp',
            'external_message_id' => 'wamid.ai.test.3',
            'sent_at' => now()->toISOString(),
        ];

        $this->postJson('/api/v1/webhooks/whatsapp', $payload, ['X-Webhook-Token' => 'ai_test_token'])->assertOk();

        $lead = Lead::query()
            ->where('company_id', $company->id)
            ->where('phone_e164', '+5511999990000')
            ->firstOrFail();

        $latestHistory = LeadStageHistory::query()
            ->where('company_id', $company->id)
            ->where('lead_id', $lead->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame($targetColumn->id, $latestHistory->to_column_id);
        $this->assertSame('ai', $latestHistory->move_source);
        $this->assertSame('Identificado via OpenAI que o cliente solicitou orçamento.', $latestHistory->reason);
    }

    public function test_webhook_triggers_ai_kanban_movement_via_llm_gemini(): void
    {
        putenv('GEMINI_API_KEY=fake-gemini-key-for-test');

        $company = Company::create(['name' => 'Empresa AI', 'slug' => 'empresa-ai']);
        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'webhook_token' => 'ai_test_token',
        ]);

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

        $targetColumn = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Pensar / Cônjuge',
            'position' => 2,
            'rule_prompt' => 'Cliente precisa de tempo para pensar',
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'matched_column_id' => $targetColumn->id,
                                        'reason' => 'Identificado via Gemini que o cliente precisa de tempo.'
                                    ])
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $payload = [
            'company_slug' => $company->slug,
            'phone' => '(11) 99999-1111',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'channel' => 'text',
            'body' => 'Vou analisar.',
            'source' => 'whatsapp',
            'external_message_id' => 'wamid.ai.test.4',
            'sent_at' => now()->toISOString(),
        ];

        $this->postJson('/api/v1/webhooks/whatsapp', $payload, ['X-Webhook-Token' => 'ai_test_token'])->assertOk();

        $lead = Lead::query()
            ->where('company_id', $company->id)
            ->where('phone_e164', '+5511999991111')
            ->firstOrFail();

        $latestHistory = LeadStageHistory::query()
            ->where('company_id', $company->id)
            ->where('lead_id', $lead->id)
            ->orderByDesc('id')
            ->firstOrFail();

        $this->assertSame($targetColumn->id, $latestHistory->to_column_id);
        $this->assertSame('ai', $latestHistory->move_source);
        $this->assertSame('Identificado via Gemini que o cliente precisa de tempo.', $latestHistory->reason);
    }

    public function test_webhook_does_not_move_if_no_rules_match(): void
    {
        $company = Company::create(['name' => 'Empresa AI', 'slug' => 'empresa-ai']);
        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'webhook_token' => 'ai_test_token',
        ]);

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

        $quoteColumn = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Orçamento Enviado',
            'position' => 2,
            'rule_prompt' => 'Cliente pediu um orçamento ou informações de preço/valores',
        ]);

        $payload = [
            'company_slug' => $company->slug,
            'phone' => '(11) 99999-2222',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'channel' => 'text',
            'body' => 'Olá, tudo bem? Apenas passando para dar um oi.',
            'source' => 'whatsapp',
            'external_message_id' => 'wamid.ai.test.5',
            'sent_at' => now()->toISOString(),
        ];

        $this->postJson('/api/v1/webhooks/whatsapp', $payload, ['X-Webhook-Token' => 'ai_test_token'])->assertOk();

        $lead = Lead::query()
            ->where('company_id', $company->id)
            ->where('phone_e164', '+5511999992222')
            ->firstOrFail();

        $histories = LeadStageHistory::query()
            ->where('company_id', $company->id)
            ->where('lead_id', $lead->id)
            ->get();

        // Lead stays in the initial column
        $this->assertCount(1, $histories);
        $this->assertSame($initialColumn->id, $histories[0]->to_column_id);
    }

    public function test_webhook_does_not_duplicate_history_if_already_in_target_column(): void
    {
        $company = Company::create(['name' => 'Empresa AI', 'slug' => 'empresa-ai']);
        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'webhook_token' => 'ai_test_token',
        ]);

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

        $quoteColumn = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Orçamento Enviado',
            'position' => 2,
            'rule_prompt' => 'Cliente pediu um orçamento ou informações de preço/valores',
        ]);

        $lead = Lead::create([
            'company_id' => $company->id,
            'phone_e164' => '+5511999993333',
            'source' => 'whatsapp',
        ]);

        // Manually place lead in target column first
        LeadStageHistory::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'from_column_id' => $initialColumn->id,
            'to_column_id' => $quoteColumn->id,
            'moved_by_user_id' => null,
            'move_source' => 'system',
            'reason' => 'Pre-placed',
            'moved_at' => now()->subMinute(),
        ]);

        $payload = [
            'company_slug' => $company->slug,
            'phone' => '(11) 99999-3333',
            'direction' => 'inbound',
            'provider' => 'whatsapp-cloud',
            'channel' => 'text',
            'body' => 'Olá, preciso do orçamento de vocês.',
            'source' => 'whatsapp',
            'external_message_id' => 'wamid.ai.test.6',
            'sent_at' => now()->toISOString(),
        ];

        $this->postJson('/api/v1/webhooks/whatsapp', $payload, ['X-Webhook-Token' => 'ai_test_token'])->assertOk();

        // History count should still be 1 (no duplicate stage history entry)
        $this->assertSame(
            1,
            LeadStageHistory::query()
                ->where('company_id', $company->id)
                ->where('lead_id', $lead->id)
                ->count()
        );
    }
}
