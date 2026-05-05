<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\KanbanColumn;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::create([
            'name' => 'Empresa Demo LEADSWHATS',
            'slug' => 'empresa-demo',
            'timezone' => 'America/Sao_Paulo',
            'work_start' => '08:00',
            'work_end' => '18:00',
            'lunch_start' => '12:00',
            'lunch_end' => '13:00',
            'active' => true,
        ]);

        User::create([
            'company_id' => $company->id,
            'name' => 'Admin Plataforma',
            'email' => 'admin@leadswhats.local',
            'password' => Hash::make('12345678'),
            'role' => 'admin',
            'active' => true,
        ]);

        User::create([
            'company_id' => $company->id,
            'name' => 'Gestor Demo',
            'email' => 'gestor@empresa.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);

        User::create([
            'company_id' => $company->id,
            'name' => 'SDR Demo',
            'email' => 'sdr@empresa.local',
            'password' => Hash::make('12345678'),
            'role' => 'sdr',
            'active' => true,
        ]);

        $pipeline = Pipeline::create([
            'company_id' => $company->id,
            'name' => 'Pipeline Comercial Padrão',
            'is_default' => true,
        ]);

        $columns = [
            ['Novo Contato', 1],
            ['Em Negociação', 2],
            ['Agendado', 3],
            ['Fechado', 4],
            ['Perdido', 5],
        ];

        foreach ($columns as [$name, $position]) {
            KanbanColumn::create([
                'company_id' => $company->id,
                'pipeline_id' => $pipeline->id,
                'name' => $name,
                'position' => $position,
                'is_terminal' => in_array($name, ['Fechado', 'Perdido'], true),
            ]);
        }
    }
}
