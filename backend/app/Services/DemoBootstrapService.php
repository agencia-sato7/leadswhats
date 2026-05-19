<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\KanbanColumn;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoBootstrapService
{
    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $company = Company::query()->updateOrCreate(
            ['slug' => 'empresa-demo'],
            [
                'name' => 'Empresa Demo',
                'timezone' => 'America/Sao_Paulo',
                'work_start' => '08:00',
                'work_end' => '18:00',
                'lunch_start' => '12:00',
                'lunch_end' => '13:00',
                'active' => true,
            ]
        );

        $users = [
            [
                'name' => 'Platform Admin Demo',
                'email' => 'platform@leadswhats.local',
                'role' => 'platform_admin',
                'company_id' => null,
            ],
            [
                'name' => 'Admin Plataforma',
                'email' => 'admin@leadswhats.local',
                'role' => 'admin',
                'company_id' => $company->id,
            ],
            [
                'name' => 'Gestor Demo',
                'email' => 'gestor@empresa.local',
                'role' => 'gestor',
                'company_id' => $company->id,
            ],
            [
                'name' => 'SDR Demo',
                'email' => 'sdr@empresa.local',
                'role' => 'sdr',
                'company_id' => $company->id,
            ],
        ];

        foreach ($users as $userData) {
            User::query()->updateOrCreate(
                ['email' => $userData['email']],
                [
                    'company_id' => $userData['company_id'],
                    'name' => $userData['name'],
                    'password' => Hash::make('12345678'),
                    'role' => $userData['role'],
                    'active' => true,
                ]
            );
        }

        $settings = CompanyBusinessSetting::query()->firstOrCreate(
            ['company_id' => $company->id],
            [
                'timezone' => 'America/Sao_Paulo',
                'workday_start_time' => '08:00:00',
                'workday_end_time' => '18:00:00',
                'lunch_start_time' => '12:00:00',
                'lunch_end_time' => '13:00:00',
                'working_days' => [1, 2, 3, 4, 5],
                'repeated_lead_window_days' => 90,
                'rescue_threshold_hours' => 24,
                'first_response_sla_minutes' => 15,
                'follow_up_sla_hours' => 24,
                'stale_conversation_hours' => 48,
                'webhook_token' => null,
            ]
        );

        $webhookTokenConfigured = true;
        if (blank($settings->webhook_token)) {
            $settings->forceFill([
                'webhook_token' => 'demo_' . Str::lower(Str::random(28)),
            ])->save();
        }

        $pipeline = Pipeline::query()->firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Pipeline Comercial Padrão'],
            ['is_default' => true]
        );

        if (!$pipeline->is_default) {
            $pipeline->forceFill(['is_default' => true])->save();
        }

        $defaultColumns = [
            ['Novo Contato', 1],
            ['Em Atendimento', 2],
            ['Proposta/Negociação', 3],
            ['Fechado', 4],
            ['Perdido', 5],
        ];

        foreach ($defaultColumns as [$name, $position]) {
            KanbanColumn::query()->updateOrCreate(
                [
                    'company_id' => $company->id,
                    'pipeline_id' => $pipeline->id,
                    'name' => $name,
                ],
                [
                    'position' => $position,
                    'is_terminal' => in_array($name, ['Fechado', 'Perdido'], true),
                ]
            );
        }

        $token = (string) $settings->fresh()->webhook_token;
        $tokenSuffix = strlen($token) >= 4 ? substr($token, -4) : $token;

        return [
            'company_ok' => true,
            'users_ok' => true,
            'settings_ok' => true,
            'pipeline_ok' => true,
            'columns_ok' => true,
            'webhook_token_configured' => $webhookTokenConfigured,
            'webhook_token_suffix' => $tokenSuffix,
        ];
    }
}
