<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\KanbanColumn;
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
        $this->assertSame('Empresa Demo', $company->name);

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
            ->where('name', 'Pipeline Comercial Padrão')
            ->first();
        $this->assertNotNull($pipeline);
        $this->assertTrue((bool) $pipeline->is_default);

        $this->assertSame(5, KanbanColumn::query()
            ->where('company_id', $company->id)
            ->where('pipeline_id', $pipeline->id)
            ->count());

        $this->assertDatabaseHas('kanban_columns', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Novo Contato',
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
            'name' => 'Proposta/Negociação',
            'position' => 3,
        ]);
        $this->assertDatabaseHas('kanban_columns', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Fechado',
            'position' => 4,
        ]);
        $this->assertDatabaseHas('kanban_columns', [
            'company_id' => $company->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Perdido',
            'position' => 5,
        ]);
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
        $this->assertSame(1, Pipeline::query()->where('company_id', $company->id)->where('name', 'Pipeline Comercial Padrão')->count());
        $this->assertSame(5, KanbanColumn::query()
            ->where('company_id', $company->id)
            ->whereIn('name', ['Novo Contato', 'Em Atendimento', 'Proposta/Negociação', 'Fechado', 'Perdido'])
            ->count());

        $this->assertSame(1, User::query()->where('email', 'admin@leadswhats.local')->count());
        $this->assertSame(1, User::query()->where('email', 'gestor@empresa.local')->count());
        $this->assertSame(1, User::query()->where('email', 'sdr@empresa.local')->count());
        $this->assertSame(1, User::query()->where('email', 'platform@leadswhats.local')->count());
        $this->assertNull(User::query()->where('email', 'platform@leadswhats.local')->value('company_id'));

        $this->assertSame(
            $settingsToken,
            (string) CompanyBusinessSetting::query()->where('company_id', $company->id)->value('webhook_token')
        );
    }
}
