<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Conversation;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Message;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MarketingIntelligenceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear keys to avoid accidental external calls (uses keyword fallback).
        putenv('GEMINI_API_KEY=');
        putenv('OPENAI_API_KEY=');
    }

    protected function tearDown(): void
    {
        putenv('GEMINI_API_KEY=');
        putenv('OPENAI_API_KEY=');
        parent::tearDown();
    }

    public function test_classify_source_by_ai_uses_fallback_and_applies_origin(): void
    {
        [$company, $gestor, $lead] = $this->makeCompanyLeadAndUser();

        Message::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'conversation_id' => $this->makeConversation($company, $lead),
            'direction' => 'inbound',
            'channel' => 'text',
            'body' => 'Olá! Vi vocês no Google.',
            'sent_at' => now(),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $gestor->email,
            'password' => '12345678',
        ])->json('token');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/v1/intelligence/leads/{$lead->id}/classify-source")
            ->assertOk()
            ->json();

        $this->assertTrue($response['data']['applied']);
        $this->assertSame('google', $response['data']['source']);
        $this->assertSame('google', \App\Models\Lead::query()->where('id', $lead->id)->value('source'));

        $this->assertDatabaseHas('lead_source_histories', [
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'new_source' => 'google',
            'change_type' => 'auto',
        ]);

        $lead->refresh();
        $this->assertSame('google', $lead->source);
        $this->assertSame('auto', $lead->source_method);
    }

    public function test_sources_summary_returns_funnel_by_source(): void
    {
        [$company, $gestor, $lead] = $this->makeCompanyLeadAndUser();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $gestor->email,
            'password' => '12345678',
        ])->json('token');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/intelligence/sources/summary')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('funnel', $response['data']);
        $this->assertArrayHasKey('by_source', $response['data']);
    }

    public function test_lookalike_export_returns_csv_for_terminal_stages(): void
    {
        [$company, $gestor, $lead] = $this->makeCompanyLeadAndUser();

        $terminalColumn = $this->makeColumn($company);
        $terminalColumn->name = 'Venda Fechada';
        $terminalColumn->position = 2;
        $terminalColumn->is_terminal = true;
        $terminalColumn->save();

        LeadStageHistory::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'from_column_id' => $this->makeColumn($company)->id,
            'to_column_id' => $terminalColumn->id,
            'move_source' => 'system',
            'reason' => 'Venda fechada',
            'moved_at' => now(),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $gestor->email,
            'password' => '12345678',
        ])->json('token');

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->get("/api/v1/intelligence/lookalike/export?stage_ids={$terminalColumn->id}")
            ->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type', ''));
        $content = $response->streamedContent();
        $this->assertStringContainsString('phone', $content);
        $this->assertStringContainsString($lead->phone_e164, $content);
    }

    /**
     * @return array{0:Company,1:User,2:Lead}
     */
    private function makeCompanyLeadAndUser(): array
    {
        $company = Company::create([
            'name' => 'Empresa Intelligence',
            'slug' => 'empresa-intelligence',
        ]);

        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'webhook_token' => 'intel_test_token',
        ]);

        $gestor = User::create([
            'company_id' => $company->id,
            'name' => 'Gestor Intelligence',
            'email' => 'gestor.intelligence@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);

        $column = $this->makeColumn($company);

        $lead = Lead::create([
            'company_id' => $company->id,
            'phone_e164' => '+5511999990000',
            'source' => 'desconhecido',
            'source_method' => 'auto',
            'source_updated_at' => now(),
        ]);

        LeadStageHistory::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'from_column_id' => null,
            'to_column_id' => $column->id,
            'move_source' => 'system',
            'moved_at' => now()->subMinute(),
        ]);

        return [$company, $gestor, $lead];
    }

    private function makePipeline(Company $company): Pipeline
    {
        return Pipeline::firstOrCreate([
            'company_id' => $company->id,
            'name' => 'Pipeline Comercial',
        ], ['is_default' => true]);
    }

    private function makeColumn(Company $company): KanbanColumn
    {
        return KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $this->makePipeline($company)->id,
            'name' => 'Novo Contato',
            'position' => 1,
        ]);
    }

    private function makeConversation(Company $company, Lead $lead): int
    {
        return Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'owner_user_id' => null,
            'status' => 'active',
            'started_at' => now(),
            'last_message_at' => now(),
        ])->id;
    }
}

