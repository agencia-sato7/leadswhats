<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\KanbanColumn;
use App\Models\Lead;
use App\Models\LeadStageHistory;
use App\Models\Pipeline;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KanbanColumnCrudApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_gets_401_on_column_crud_endpoints(): void
    {
        $this->postJson('/api/v1/pipelines/1/columns', [
            'name' => 'Em negociação',
            'position' => 2,
            'rule' => 'Cliente demonstrou interesse e pediu detalhes',
        ])->assertUnauthorized();

        $this->patchJson('/api/v1/kanban-columns/1', [
            'name' => 'Negociação avançada',
        ])->assertUnauthorized();

        $this->deleteJson('/api/v1/kanban-columns/1')->assertUnauthorized();
    }

    public function test_gestor_creates_column_successfully(): void
    {
        [$company, $gestor, $pipeline] = $this->seedCompanyUserAndPipeline('gestor', 'gestor.column.create@test.local');

        $token = $this->login($gestor->email);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/pipelines/' . $pipeline->id . '/columns', [
                'name' => 'Em negociação',
                'position' => 2,
                'rule' => 'Cliente demonstrou interesse e pediu detalhes',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Kanban column created successfully.')
            ->assertJsonPath('data.pipeline_id', $pipeline->id)
            ->assertJsonPath('data.name', 'Em negociação')
            ->assertJsonPath('data.position', 2)
            ->assertJsonPath('data.rule', 'Cliente demonstrou interesse e pediu detalhes');

        $this->assertDatabaseHas('kanban_columns', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Em negociação',
            'position' => 2,
            'rule_prompt' => 'Cliente demonstrou interesse e pediu detalhes',
        ]);
    }

    public function test_admin_creates_column_successfully(): void
    {
        [, $admin, $pipeline] = $this->seedCompanyUserAndPipeline('admin', 'admin.column.create@test.local');

        $token = $this->login($admin->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/pipelines/' . $pipeline->id . '/columns', [
                'name' => 'Qualificação',
                'position' => 3,
                'rule' => 'Perguntas iniciais respondidas',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Qualificação')
            ->assertJsonPath('data.position', 3);
    }

    public function test_sdr_cannot_create_column(): void
    {
        [, $sdr, $pipeline] = $this->seedCompanyUserAndPipeline('sdr', 'sdr.column.create@test.local');

        $token = $this->login($sdr->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/pipelines/' . $pipeline->id . '/columns', [
                'name' => 'Sem permissão',
                'position' => 4,
                'rule' => null,
            ])
            ->assertForbidden();
    }

    public function test_gestor_edits_column_successfully(): void
    {
        [$company, $gestor, $pipeline] = $this->seedCompanyUserAndPipeline('gestor', 'gestor.column.update@test.local');

        $column = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Negociação',
            'position' => 2,
            'rule_prompt' => null,
        ]);

        $token = $this->login($gestor->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson('/api/v1/kanban-columns/' . $column->id, [
                'name' => 'Negociação avançada',
                'position' => 5,
                'rule' => 'Cliente solicitou proposta formal',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Kanban column updated successfully.')
            ->assertJsonPath('data.name', 'Negociação avançada')
            ->assertJsonPath('data.position', 5)
            ->assertJsonPath('data.rule', 'Cliente solicitou proposta formal');
    }

    public function test_user_cannot_edit_column_from_another_company(): void
    {
        [$companyA, $gestorA, $pipelineA] = $this->seedCompanyUserAndPipeline('gestor', 'gestor.column.cross@test.local', 'empresa-a');

        $companyB = Company::create(['name' => 'Empresa B', 'slug' => 'empresa-b']);
        $pipelineB = Pipeline::create([
            'company_id' => $companyB->id,
            'name' => 'Pipeline B',
            'is_default' => true,
        ]);
        $columnB = KanbanColumn::create([
            'company_id' => $companyB->id,
            'pipeline_id' => $pipelineB->id,
            'name' => 'Coluna B',
            'position' => 1,
            'rule_prompt' => null,
        ]);

        KanbanColumn::create([
            'company_id' => $companyA->id,
            'pipeline_id' => $pipelineA->id,
            'name' => 'Coluna A',
            'position' => 1,
            'rule_prompt' => null,
        ]);

        $token = $this->login($gestorA->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson('/api/v1/kanban-columns/' . $columnB->id, [
                'name' => 'Tentativa indevida',
            ])
            ->assertNotFound();
    }

    public function test_cannot_remove_column_with_cards_or_leads_associated(): void
    {
        [$company, $gestor, $pipeline] = $this->seedCompanyUserAndPipeline('gestor', 'gestor.column.delete.blocked@test.local');

        $column = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Negociação',
            'position' => 2,
            'rule_prompt' => null,
        ]);

        $lead = Lead::create([
            'company_id' => $company->id,
            'name' => 'Lead Bloqueio',
            'phone_e164' => '+5511990001000',
            'source' => 'instagram',
        ]);

        Conversation::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'status' => 'active',
            'started_at' => Carbon::parse('2026-05-06 09:00:00'),
            'last_message_at' => Carbon::parse('2026-05-06 09:10:00'),
        ]);

        LeadStageHistory::create([
            'company_id' => $company->id,
            'lead_id' => $lead->id,
            'from_column_id' => null,
            'to_column_id' => $column->id,
            'moved_by_user_id' => $gestor->id,
            'move_source' => 'manual',
            'reason' => 'Entrada',
            'moved_at' => Carbon::parse('2026-05-06 09:05:00'),
        ]);

        $token = $this->login($gestor->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->deleteJson('/api/v1/kanban-columns/' . $column->id)
            ->assertStatus(422)
            ->assertJsonPath('errors.column.0', 'Não é possível remover coluna com cards/leads associados.');
    }

    public function test_remove_empty_column_successfully_returns_204(): void
    {
        [$company, $gestor, $pipeline] = $this->seedCompanyUserAndPipeline('gestor', 'gestor.column.delete.ok@test.local');

        $column = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Descartar',
            'position' => 8,
            'rule_prompt' => null,
        ]);

        $token = $this->login($gestor->email);

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->deleteJson('/api/v1/kanban-columns/' . $column->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('kanban_columns', ['id' => $column->id]);
    }

    public function test_positions_are_respected_and_same_position_ordering_is_predictable_by_id(): void
    {
        [$company, $gestor, $pipeline] = $this->seedCompanyUserAndPipeline('gestor', 'gestor.column.order@test.local');

        $firstSame = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'A - posição 2',
            'position' => 2,
            'rule_prompt' => null,
        ]);

        $secondSame = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'B - posição 2',
            'position' => 2,
            'rule_prompt' => null,
        ]);

        $first = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'C - posição 1',
            'position' => 1,
            'rule_prompt' => null,
        ]);

        $token = $this->login($gestor->email);

        $kanbanResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/pipelines/' . $pipeline->id . '/kanban');

        $kanbanResponse->assertOk()
            ->assertJsonPath('data.columns.0.id', $first->id)
            ->assertJsonPath('data.columns.1.id', $firstSame->id)
            ->assertJsonPath('data.columns.2.id', $secondSame->id);
    }

    public function test_rule_is_saved_and_kanban_reflects_create_edit_and_remove(): void
    {
        [$company, $gestor, $pipeline] = $this->seedCompanyUserAndPipeline('gestor', 'gestor.column.fullflow@test.local');

        $baseColumn = KanbanColumn::create([
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Base',
            'position' => 1,
            'rule_prompt' => null,
        ]);

        $token = $this->login($gestor->email);

        $createResponse = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/pipelines/' . $pipeline->id . '/columns', [
                'name' => 'Nova Coluna',
                'position' => 2,
                'rule' => 'Regra inicial',
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.rule', 'Regra inicial');

        $createdColumnId = (int) $createResponse->json('data.id');

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->patchJson('/api/v1/kanban-columns/' . $createdColumnId, [
                'name' => 'Nova Coluna Editada',
                'rule' => 'Regra atualizada',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nova Coluna Editada')
            ->assertJsonPath('data.rule', 'Regra atualizada');

        $kanbanAfterEdit = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/pipelines/' . $pipeline->id . '/kanban');

        $kanbanAfterEdit->assertOk()
            ->assertJsonPath('data.columns.0.id', $baseColumn->id)
            ->assertJsonPath('data.columns.1.id', $createdColumnId)
            ->assertJsonPath('data.columns.1.rule', 'Regra atualizada');

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->deleteJson('/api/v1/kanban-columns/' . $createdColumnId)
            ->assertNoContent();

        $kanbanAfterDelete = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/pipelines/' . $pipeline->id . '/kanban');

        $kanbanAfterDelete->assertOk()
            ->assertJsonCount(1, 'data.columns')
            ->assertJsonPath('data.columns.0.id', $baseColumn->id);
    }

    private function seedCompanyUserAndPipeline(string $role, string $email, string $slug = 'empresa-a'): array
    {
        $company = Company::create(['name' => 'Empresa A', 'slug' => $slug]);

        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Usuário Teste',
            'email' => $email,
            'password' => Hash::make('12345678'),
            'role' => $role,
            'active' => true,
        ]);

        $pipeline = Pipeline::create([
            'company_id' => $company->id,
            'name' => 'Pipeline Comercial',
            'is_default' => true,
        ]);

        return [$company, $user, $pipeline];
    }

    private function login(string $email): string
    {
        return (string) $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => '12345678',
        ])->json('token');
    }
}
