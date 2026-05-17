<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\KanbanColumn;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::firstOrCreate(
            ['slug' => 'empresa-demo'],
            [
                'name' => 'Empresa Demo LEADSWHATS',
                'timezone' => 'America/Sao_Paulo',
                'work_start' => '08:00',
                'work_end' => '18:00',
                'lunch_start' => '12:00',
                'lunch_end' => '13:00',
                'active' => true,
            ]
        );

        CompanyBusinessSetting::firstOrCreate(
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

        User::firstOrCreate(
            ['email' => 'admin@leadswhats.local'],
            [
                'company_id' => $company->id,
                'name' => 'Admin Plataforma',
                'password' => Hash::make('12345678'),
                'role' => 'admin',
                'active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'gestor@empresa.local'],
            [
                'company_id' => $company->id,
                'name' => 'Gestor Demo',
                'password' => Hash::make('12345678'),
                'role' => 'gestor',
                'active' => true,
            ]
        );

        User::firstOrCreate(
            ['email' => 'sdr@empresa.local'],
            [
                'company_id' => $company->id,
                'name' => 'SDR Demo',
                'password' => Hash::make('12345678'),
                'role' => 'sdr',
                'active' => true,
            ]
        );

        $pipeline = Pipeline::firstOrCreate(
            ['company_id' => $company->id, 'name' => 'Pipeline Comercial Padrão'],
            ['is_default' => true]
        );

        $columns = [
            ['Novo Contato', 1],
            ['Em Negociação', 2],
            ['Agendado', 3],
            ['Fechado', 4],
            ['Perdido', 5],
        ];

        foreach ($columns as [$name, $position]) {
            KanbanColumn::firstOrCreate(
                [
                    'company_id' => $company->id,
                    'pipeline_id' => $pipeline->id,
                    'position' => $position,
                ],
                [
                    'name' => $name,
                    'is_terminal' => in_array($name, ['Fechado', 'Perdido'], true),
                ]
            );
        }
    }
}
