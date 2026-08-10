<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\ConversationQualityScore;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KanbanRecommendationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_kanban_exposes_latest_commercial_analysis_without_technical_rule_on_card(): void
    {
        [$token, $lead, $conversation, $current, $recommended, $analysis, $pipeline] = $this->scenario();

        $this->withToken($token)
            ->getJson('/api/v1/pipelines/'.$pipeline->id.'/kanban')
            ->assertOk()
            ->assertJsonPath('data.columns.0.cards.0.lead_id', $lead->id)
            ->assertJsonPath('data.columns.0.cards.0.latest_analysis.id', $analysis->id)
            ->assertJsonPath('data.columns.0.cards.0.latest_analysis.conversation_id', $conversation->id)
            ->assertJsonPath('data.columns.0.cards.0.latest_analysis.score', 84)
            ->assertJsonPath('data.columns.0.cards.0.latest_analysis.intent', 'Compra em curto prazo')
            ->assertJsonPath('data.columns.0.cards.0.latest_analysis.objections.0', 'Prazo de implantação')
            ->assertJsonPath('data.columns.0.cards.0.latest_analysis.commercial_data.budget', 'R$ 12.000')
            ->assertJsonPath('data.columns.0.cards.0.latest_analysis.recommended_kanban_column_id', $recommended->id)
            ->assertJsonPath('data.columns.0.cards.0.latest_analysis.recommended_kanban_column_name', 'Proposta')
            ->assertJsonPath('data.columns.0.cards.0.latest_analysis.recommendation_decision', null)
            ->assertJsonPath('data.columns.0.rule', 'Entrada e qualificação inicial');

        $this->assertNotSame($current->id, $recommended->id);
    }

    public function test_gestor_applies_ai_recommendation_through_movement_service_and_audits_origin(): void
    {
        [$token, $lead, , $current, $recommended, $analysis] = $this->scenario();

        $this->withToken($token)
            ->postJson('/api/v1/leads/'.$lead->id.'/recommendations/'.$analysis->id.'/apply')
            ->assertOk()
            ->assertJsonPath('data.decision', 'applied')
            ->assertJsonPath('data.movement.kanban_column_id', $recommended->id)
            ->assertJsonPath('data.movement.movement_type', 'ai_recommendation_accepted')
            ->assertJsonPath('data.movement.history_created', true);

        $this->assertDatabaseHas('lead_stage_histories', [
            'lead_id' => $lead->id,
            'from_column_id' => $current->id,
            'to_column_id' => $recommended->id,
            'move_source' => 'ai_recommendation_accepted',
            'reason' => 'Recomendação da IA aceita pelo gestor.',
        ]);
        $this->assertDatabaseHas('conversation_analysis_decisions', [
            'conversation_quality_score_id' => $analysis->id,
            'lead_id' => $lead->id,
            'decision' => 'applied',
            'recommended_column_id' => $recommended->id,
            'applied_column_id' => $recommended->id,
        ]);
    }

    public function test_gestor_keeps_current_stage_without_creating_movement(): void
    {
        [$token, $lead, , $current, $recommended, $analysis] = $this->scenario();
        $historyCount = LeadStageHistory::query()->where('lead_id', $lead->id)->count();

        $this->withToken($token)
            ->postJson('/api/v1/leads/'.$lead->id.'/recommendations/'.$analysis->id.'/keep-current')
            ->assertOk()
            ->assertJsonPath('data.decision', 'kept_current');

        $this->assertSame($historyCount, LeadStageHistory::query()->where('lead_id', $lead->id)->count());
        $this->assertDatabaseHas('conversation_analysis_decisions', [
            'conversation_quality_score_id' => $analysis->id,
            'lead_id' => $lead->id,
            'decision' => 'kept_current',
            'from_column_id' => $current->id,
            'recommended_column_id' => $recommended->id,
            'applied_column_id' => $current->id,
        ]);
    }

    /**
     * @return array{string,Lead,Conversation,KanbanColumn,KanbanColumn,ConversationQualityScore,Pipeline}
     */
    private function scenario(): array
    {
        $company = Company::create(['name' => 'Empresa IA', 'slug' => 'empresa-ia']);
        $gestor = User::create([
            'company_id' => $company->id,
            'name' => 'Gestor IA',
            'email' => 'gestor.kanban.ia@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);
        $pipeline = Pipeline::create([
            'company_id' => $company->id,
            'name' => 'Pipeline Comercial',
            'is_default' => true,
        ]);
        $current = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Qualificação',
            'position' => 1,
            'rule_prompt' => 'Entrada e qualificação inicial',
        ]);
        $recommended = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Proposta',
            'position' => 2,
            'rule_prompt' => 'Lead validou necessidade e orçamento',
        ]);
        $lead = Lead::create([
            'company_id' => $company->id,
            'owner_user_id' => $gestor->id,
            'name' => 'Marina Costa',
            'phone_e164' => '+5511999991000',
            'source' => 'instagram',
        ]);
        $conversation = Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'owner_user_id' => $gestor->id,
            'status' => 'active',
            'started_at' => now()->subHour(),
            'last_message_at' => now()->subMinutes(10),
        ]);
        LeadStageHistory::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'to_column_id' => $current->id,
            'moved_by_user_id' => $gestor->id,
            'move_source' => 'manual',
            'reason' => 'Entrada no funil',
            'moved_at' => now()->subHour(),
        ]);
        $analysis = ConversationQualityScore::create([
            'company_id' => $company->id,
            'conversation_id' => $conversation->id,
            'lead_id' => $lead->id,
            'owner_user_id' => $gestor->id,
            'score' => 84,
            'summary' => 'Lead qualificado, com orçamento e urgência definidos.',
            'intent' => 'Compra em curto prazo',
            'objections' => ['Prazo de implantação'],
            'positive_points' => ['Boa descoberta'],
            'errors' => [],
            'improvement_suggestion' => 'Confirmar participantes da decisão.',
            'commercial_data' => ['budget' => 'R$ 12.000', 'next_step' => 'Enviar proposta amanhã'],
            'criteria_scores' => ['descoberta' => 90],
            'recommended_kanban_column_id' => $recommended->id,
            'classification_reason' => 'Necessidade e orçamento confirmados.',
            'confidence' => 0.91,
            'analysis_version' => 1,
            'prompt_version' => 'v1',
            'model_provider' => 'fake',
            'model_name' => 'test',
            'transcript_hash' => str_repeat('a', 64),
            'analyzed_at' => now(),
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $gestor->email,
            'password' => '12345678',
        ])->json('token');

        return [$token, $lead, $conversation, $current, $recommended, $analysis, $pipeline];
    }
}
