<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Conversation;
use App\Models\ConversationQualityScore;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Message;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoBootstrapCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_bootstrap_creates_minimum_demo_data(): void
    {
        $this->artisan('leadswhats:demo-bootstrap')
            ->expectsOutputToContain('LEADSWHATS demo bootstrap concluído.')
            ->assertExitCode(0);

        $company = Company::query()->where('slug', 'empresa-demo')->first();
        $this->assertNotNull($company);
        $this->assertSame('Oral Sin - Unidade Demo', $company->name);

        $this->assertDatabaseHas('users', [
            'company_id' => null,
            'email' => 'platform@leadswhats.local',
            'role' => 'platform_admin',
            'active' => true,
        ]);
        $this->assertDatabaseHas('users', [
            'company_id' => $company->id,
            'email' => 'admin@leadswhats.local',
            'role' => 'admin',
            'active' => true,
        ]);
        $this->assertDatabaseHas('users', [
            'company_id' => $company->id,
            'email' => 'gestor@empresa.local',
            'role' => 'gestor',
            'active' => true,
        ]);
        $this->assertDatabaseHas('users', [
            'company_id' => $company->id,
            'email' => 'sdr@empresa.local',
            'role' => 'sdr',
            'active' => true,
        ]);
        $this->assertDatabaseHas('users', [
            'company_id' => $company->id,
            'email' => 'marina@empresa.local',
            'role' => 'sdr',
            'active' => true,
        ]);
        $this->assertDatabaseHas('users', [
            'company_id' => $company->id,
            'email' => 'rafael@empresa.local',
            'role' => 'sdr',
            'active' => true,
        ]);

        $settings = CompanyBusinessSetting::query()->where('company_id', $company->id)->first();
        $this->assertNotNull($settings);
        $this->assertSame('America/Sao_Paulo', $settings->timezone);
        $this->assertSame('08:00:00', $settings->workday_start_time);
        $this->assertSame('18:00:00', $settings->workday_end_time);
        $this->assertSame('12:00:00', $settings->lunch_start_time);
        $this->assertSame('13:00:00', $settings->lunch_end_time);
        $this->assertSame([1, 2, 3, 4, 5], $settings->working_days);
        $this->assertSame(90, $settings->repeated_lead_window_days);
        $this->assertSame(24, $settings->rescue_threshold_hours);
        $this->assertSame(15, $settings->first_response_sla_minutes);
        $this->assertSame(24, $settings->follow_up_sla_hours);
        $this->assertSame(48, $settings->stale_conversation_hours);
        $this->assertNotEmpty($settings->webhook_token);

        $pipeline = Pipeline::query()
            ->where('company_id', $company->id)
            ->where('name', 'Jornada do Paciente - Oral Sin')
            ->first();
        $this->assertNotNull($pipeline);
        $this->assertTrue((bool) $pipeline->is_default);

        $this->assertSame(7, KanbanColumn::query()
            ->where('company_id', $company->id)
            ->where('pipeline_id', $pipeline->id)
            ->count());

        $this->assertDatabaseHas('kanban_columns', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Novo Lead',
            'position' => 1,
        ]);
        $this->assertDatabaseHas('kanban_columns', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Em Atendimento',
            'position' => 2,
        ]);
        $this->assertDatabaseHas('kanban_columns', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Avaliação Agendada',
            'position' => 3,
        ]);
        $this->assertDatabaseHas('kanban_columns', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Avaliação Realizada',
            'position' => 4,
        ]);
        $this->assertDatabaseHas('kanban_columns', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Em Negociação',
            'position' => 5,
        ]);
        $this->assertDatabaseHas('kanban_columns', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Tratamento Fechado',
            'position' => 6,
        ]);
        $this->assertDatabaseHas('kanban_columns', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Não Convertido',
            'position' => 7,
        ]);

        $this->assertSame(14, Lead::query()->where('company_id', $company->id)->count());
        $this->assertSame(14, Conversation::query()->where('company_id', $company->id)->count());
        $this->assertSame(82, Message::query()->where('company_id', $company->id)->count());
        $this->assertSame(10, ConversationQualityScore::query()->where('company_id', $company->id)->count());

        $this->assertSame(6, Lead::query()
            ->where('company_id', $company->id)
            ->whereDate('created_at', today())
            ->where('is_repeat_lead', false)
            ->count());
        $this->assertSame(1, Lead::query()
            ->where('company_id', $company->id)
            ->whereDate('created_at', today())
            ->where('is_repeat_lead', true)
            ->count());

        $this->assertSame(3, Lead::query()
            ->where('company_id', $company->id)
            ->whereNotNull('owner_user_id')
            ->distinct('owner_user_id')
            ->count('owner_user_id'));
        $this->assertSame(2, Lead::query()->where('company_id', $company->id)->whereNull('owner_user_id')->count());
        $this->assertSame([
            'facebook' => 2,
            'google' => 4,
            'indicacao' => 2,
            'instagram' => 4,
            'site' => 2,
        ], Lead::query()
            ->where('company_id', $company->id)
            ->selectRaw('source, COUNT(*) as total')
            ->groupBy('source')
            ->orderBy('source')
            ->pluck('total', 'source')
            ->map(fn ($total) => (int) $total)
            ->all());
        $this->assertGreaterThanOrEqual(2, Conversation::query()
            ->where('company_id', $company->id)
            ->where('last_message_at', '<', now()->subHours(24))
            ->count());

        $scores = ConversationQualityScore::query()->where('company_id', $company->id)->get();
        $this->assertTrue($scores->contains(fn (ConversationQualityScore $score) => $score->score >= 80));
        $this->assertTrue($scores->contains(fn (ConversationQualityScore $score) => $score->score >= 55 && $score->score < 80));
        $this->assertTrue($scores->contains(fn (ConversationQualityScore $score) => $score->score < 55));
        $this->assertTrue($scores->every(fn (ConversationQualityScore $score) => filled($score->commercial_data['treatment_interest'] ?? null)));
        $this->assertTrue($scores->every(fn (ConversationQualityScore $score) => filled($score->commercial_data['main_need'] ?? null)));
        $this->assertTrue($scores->every(fn (ConversationQualityScore $score) => filled($score->commercial_data['next_step'] ?? null)));
        $this->assertTrue($scores->every(fn (ConversationQualityScore $score) => ! array_key_exists('budget', $score->commercial_data)));
        $this->assertTrue($scores->every(fn (ConversationQualityScore $score) => $score->model_provider === 'fake'));
        $this->assertTrue($scores->every(fn (ConversationQualityScore $score) => $score->model_name === 'demo-fixture'));

        $withoutAnalysis = Conversation::query()
            ->where('conversations.company_id', $company->id)
            ->join('leads', 'leads.id', '=', 'conversations.lead_id')
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('conversation_quality_scores')
                ->whereColumn('conversation_quality_scores.conversation_id', 'conversations.id'))
            ->orderBy('leads.name')
            ->pluck('leads.name')
            ->all();
        $this->assertSame(['Eduardo Ramos', 'Larissa Mendes', 'Paula Azevedo', 'Renato Alves'], $withoutAnalysis);

        foreach ([
            'Paula Azevedo' => ['stage' => 'Avaliação Agendada', 'messages' => 10],
            'Eduardo Ramos' => ['stage' => 'Em Atendimento', 'messages' => 6],
        ] as $leadName => $expected) {
            $lead = Lead::query()->where('company_id', $company->id)->where('name', $leadName)->firstOrFail();
            $conversation = Conversation::query()->where('lead_id', $lead->id)->firstOrFail();

            $this->assertTrue((bool) data_get($lead->metadata, 'demo'));
            $this->assertSame('oral-sin', data_get($lead->metadata, 'fixture'));
            $this->assertSame(0, ConversationQualityScore::query()->where('conversation_id', $conversation->id)->count());
            $this->assertSame($expected['messages'], Message::query()->where('conversation_id', $conversation->id)->count());
            $this->assertTrue(Message::query()->where('conversation_id', $conversation->id)->get()->every(
                fn (Message $message) => $message->provider === 'fake'
                    && data_get($message->raw_payload, 'demo') === true
                    && data_get($message->raw_payload, 'fixture') === 'oral-sin'
            ));
            $this->assertSame($expected['stage'], LeadStageHistory::query()
                ->where('lead_id', $lead->id)
                ->join('kanban_columns', 'kanban_columns.id', '=', 'lead_stage_histories.to_column_id')
                ->value('kanban_columns.name'));
        }

        $recommendations = ConversationQualityScore::query()
            ->where('conversation_quality_scores.company_id', $company->id)
            ->join('lead_stage_histories', 'lead_stage_histories.lead_id', '=', 'conversation_quality_scores.lead_id')
            ->get(['conversation_quality_scores.recommended_kanban_column_id', 'lead_stage_histories.to_column_id']);
        $this->assertSame(3, $recommendations->filter(
            fn ($recommendation) => $recommendation->recommended_kanban_column_id !== $recommendation->to_column_id
        )->count());
        $this->assertSame(7, $recommendations->filter(
            fn ($recommendation) => $recommendation->recommended_kanban_column_id === $recommendation->to_column_id
        )->count());

        $cardsPerStage = LeadStageHistory::query()
            ->where('lead_stage_histories.company_id', $company->id)
            ->join('kanban_columns', 'kanban_columns.id', '=', 'lead_stage_histories.to_column_id')
            ->selectRaw('kanban_columns.name, COUNT(*) as total')
            ->groupBy('kanban_columns.name')
            ->orderBy('kanban_columns.name')
            ->pluck('total', 'name')
            ->map(fn ($total) => (int) $total)
            ->all();
        $this->assertSame([
            'Avaliação Agendada' => 3,
            'Avaliação Realizada' => 1,
            'Em Atendimento' => 3,
            'Em Negociação' => 2,
            'Novo Lead' => 2,
            'Não Convertido' => 1,
            'Tratamento Fechado' => 2,
        ], $cardsPerStage);
    }

    public function test_demo_bootstrap_is_idempotent_and_does_not_duplicate_records(): void
    {
        $this->artisan('leadswhats:demo-bootstrap')->assertExitCode(0);

        $company = Company::query()->where('slug', 'empresa-demo')->firstOrFail();
        $settingsToken = (string) CompanyBusinessSetting::query()
            ->where('company_id', $company->id)
            ->value('webhook_token');

        $this->artisan('leadswhats:demo-bootstrap')->assertExitCode(0);

        $this->assertSame(1, Company::query()->where('slug', 'empresa-demo')->count());
        $this->assertSame(1, CompanyBusinessSetting::query()->where('company_id', $company->id)->count());
        $this->assertSame(1, Pipeline::query()->where('company_id', $company->id)->where('name', 'Jornada do Paciente - Oral Sin')->count());
        $this->assertSame(7, KanbanColumn::query()
            ->where('company_id', $company->id)
            ->whereIn('name', ['Novo Lead', 'Em Atendimento', 'Avaliação Agendada', 'Avaliação Realizada', 'Em Negociação', 'Tratamento Fechado', 'Não Convertido'])
            ->count());

        $this->assertSame(1, User::query()->where('email', 'admin@leadswhats.local')->count());
        $this->assertSame(1, User::query()->where('email', 'gestor@empresa.local')->count());
        $this->assertSame(1, User::query()->where('email', 'sdr@empresa.local')->count());
        $this->assertSame(1, User::query()->where('email', 'marina@empresa.local')->count());
        $this->assertSame(1, User::query()->where('email', 'rafael@empresa.local')->count());
        $this->assertSame(1, User::query()->where('email', 'platform@leadswhats.local')->count());
        $this->assertNull(User::query()->where('email', 'platform@leadswhats.local')->value('company_id'));
        $this->assertSame(14, Lead::query()->where('company_id', $company->id)->count());
        $this->assertSame(14, Conversation::query()->where('company_id', $company->id)->count());
        $this->assertSame(82, Message::query()->where('company_id', $company->id)->count());
        $this->assertSame(14, LeadStageHistory::query()->where('company_id', $company->id)->count());
        $this->assertSame(10, ConversationQualityScore::query()->where('company_id', $company->id)->count());
        $this->assertSame(0, KanbanColumn::query()
            ->where('company_id', $company->id)
            ->selectRaw('name, COUNT(*) as total')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->count());

        $this->assertSame(
            $settingsToken,
            (string) CompanyBusinessSetting::query()->where('company_id', $company->id)->value('webhook_token')
        );
    }

    public function test_demo_bootstrap_preserves_real_analysis_on_a_fixture_conversation(): void
    {
        $this->artisan('leadswhats:demo-bootstrap')->assertExitCode(0);

        $lead = Lead::query()->where('name', 'Renato Alves')->firstOrFail();
        $conversation = Conversation::query()->where('lead_id', $lead->id)->firstOrFail();

        ConversationQualityScore::query()->create([
            'company_id' => $lead->company_id,
            'conversation_id' => $conversation->id,
            'lead_id' => $lead->id,
            'owner_user_id' => null,
            'score' => 77,
            'summary' => 'Análise real preservada para validação do isolamento.',
            'intent' => 'Revisar condições comerciais',
            'objections' => ['Planejamento de pagamento'],
            'positive_points' => ['Próximo contato solicitado'],
            'errors' => [],
            'improvement_suggestion' => 'Realizar o contato combinado.',
            'commercial_data' => ['next_step' => 'Contato com a consultora'],
            'criteria_scores' => ['clareza' => 77],
            'recommended_kanban_column_id' => null,
            'classification_reason' => 'Snapshot criado como análise real no teste.',
            'confidence' => 0.8,
            'analysis_version' => 1,
            'prompt_version' => 'real-v1',
            'model_provider' => 'python',
            'model_name' => 'modelo-real',
            'source_last_message_id' => null,
            'transcript_hash' => str_repeat('a', 64),
            'analyzed_at' => now(),
        ]);

        $this->artisan('leadswhats:demo-bootstrap')->assertExitCode(0);

        $this->assertDatabaseHas('conversation_quality_scores', [
            'conversation_id' => $conversation->id,
            'analysis_version' => 1,
            'model_provider' => 'python',
            'model_name' => 'modelo-real',
        ]);
        $this->assertSame(10, ConversationQualityScore::query()->where('model_name', 'demo-fixture')->count());
        $this->assertSame(1, ConversationQualityScore::query()->where('model_name', 'modelo-real')->count());
    }
}
