<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\KanbanColumn;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformCompanyService
{
    public function __construct(private readonly AccessControlService $accessControl) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCompanies(): array
    {
        return Company::query()
            ->withCount(['users', 'pipelines'])
            ->withExists(['businessSetting as has_business_settings'])
            ->orderBy('id')
            ->get()
            ->map(fn (Company $company): array => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'active' => (bool) $company->active,
                'users_count' => (int) $company->users_count,
                'pipelines_count' => (int) $company->pipelines_count,
                'has_business_settings' => (bool) $company->has_business_settings,
                'created_at' => optional($company->created_at)?->toISOString(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param array{
     *   company: array{name:string,slug:string},
     *   admin_user: array{name:string,email:string,password:string},
     *   settings: array{
     *      timezone:string,workday_start_time:string,workday_end_time:string,
     *      lunch_start_time:?string,lunch_end_time:?string,working_days:array<int,int>,
     *      repeated_lead_window_days:int,rescue_threshold_hours:int,first_response_sla_minutes:int,
     *      follow_up_sla_hours:int,stale_conversation_hours:int
     *   }
     * } $payload
     * @return array<string, mixed>
     */
    public function createCompany(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            $company = Company::query()->create([
                'name' => $payload['company']['name'],
                'slug' => $payload['company']['slug'],
            ]);

            $profiles = $this->accessControl->createProfilesForCompany($company);

            $adminUser = User::query()->create([
                'company_id' => $company->id,
                'access_profile_id' => $profiles['administrador']->id,
                'name' => $payload['admin_user']['name'],
                'email' => $payload['admin_user']['email'],
                'password' => Hash::make($payload['admin_user']['password']),
                'role' => 'admin',
                'active' => true,
            ]);

            $settingsPayload = $payload['settings'];
            $settings = CompanyBusinessSetting::query()->create([
                'company_id' => $company->id,
                'timezone' => $settingsPayload['timezone'],
                'workday_start_time' => $settingsPayload['workday_start_time'],
                'workday_end_time' => $settingsPayload['workday_end_time'],
                'lunch_start_time' => $settingsPayload['lunch_start_time'],
                'lunch_end_time' => $settingsPayload['lunch_end_time'],
                'working_days' => $settingsPayload['working_days'],
                'repeated_lead_window_days' => $settingsPayload['repeated_lead_window_days'],
                'rescue_threshold_hours' => $settingsPayload['rescue_threshold_hours'],
                'first_response_sla_minutes' => $settingsPayload['first_response_sla_minutes'],
                'follow_up_sla_hours' => $settingsPayload['follow_up_sla_hours'],
                'stale_conversation_hours' => $settingsPayload['stale_conversation_hours'],
                'webhook_token' => 'demo_'.Str::lower(Str::random(28)),
            ]);

            $pipeline = Pipeline::query()->create([
                'company_id' => $company->id,
                'name' => 'Pipeline Comercial Padrão',
                'is_default' => true,
            ]);

            $columnNames = [
                'Novo Contato',
                'Em Atendimento',
                'Proposta/Negociação',
                'Fechado',
                'Perdido',
            ];

            foreach ($columnNames as $index => $columnName) {
                KanbanColumn::query()->create([
                    'company_id' => $company->id,
                    'pipeline_id' => $pipeline->id,
                    'name' => $columnName,
                    'position' => $index + 1,
                    'is_terminal' => in_array($columnName, ['Fechado', 'Perdido'], true),
                ]);
            }

            $token = (string) $settings->webhook_token;
            $maskedToken = '****'.(strlen($token) >= 4 ? substr($token, -4) : $token);

            return [
                'company' => [
                    'id' => $company->id,
                    'name' => $company->name,
                    'slug' => $company->slug,
                    'created_at' => optional($company->created_at)?->toISOString(),
                ],
                'admin_user' => [
                    'id' => $adminUser->id,
                    'name' => $adminUser->name,
                    'email' => $adminUser->email,
                    'role' => $adminUser->role?->value ?? (string) $adminUser->role,
                ],
                'settings' => [
                    'timezone' => $settings->timezone,
                    'workday_start_time' => $settings->workday_start_time,
                    'workday_end_time' => $settings->workday_end_time,
                    'lunch_start_time' => $settings->lunch_start_time,
                    'lunch_end_time' => $settings->lunch_end_time,
                    'working_days' => $settings->working_days,
                    'repeated_lead_window_days' => $settings->repeated_lead_window_days,
                    'rescue_threshold_hours' => $settings->rescue_threshold_hours,
                    'first_response_sla_minutes' => $settings->first_response_sla_minutes,
                    'follow_up_sla_hours' => $settings->follow_up_sla_hours,
                    'stale_conversation_hours' => $settings->stale_conversation_hours,
                    'webhook_token_configured' => true,
                    'masked_webhook_token' => $maskedToken,
                ],
                'pipeline' => [
                    'id' => $pipeline->id,
                    'name' => $pipeline->name,
                    'is_default' => (bool) $pipeline->is_default,
                    'columns_count' => 5,
                ],
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function showCompany(int $companyId): array
    {
        $company = Company::query()->findOrFail($companyId);

        $adminUsers = User::query()
            ->where('company_id', $company->id)
            ->where('role', 'admin')
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'active', 'created_at'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value ?? (string) $user->role,
                'active' => (bool) $user->active,
                'created_at' => optional($user->created_at)?->toISOString(),
            ])
            ->values()
            ->all();

        $settings = CompanyBusinessSetting::query()->where('company_id', $company->id)->first();
        $pipeline = Pipeline::query()->where('company_id', $company->id)->where('is_default', true)->first();
        $columns = $pipeline
            ? KanbanColumn::query()
                ->where('company_id', $company->id)
                ->where('pipeline_id', $pipeline->id)
                ->orderBy('position')
                ->get(['id', 'name', 'position', 'is_terminal'])
                ->map(fn (KanbanColumn $column): array => [
                    'id' => $column->id,
                    'name' => $column->name,
                    'position' => $column->position,
                    'is_terminal' => (bool) $column->is_terminal,
                ])
                ->values()
                ->all()
            : [];

        return [
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'active' => (bool) $company->active,
                'created_at' => optional($company->created_at)?->toISOString(),
            ],
            'admin_users' => $adminUsers,
            'settings' => $settings ? [
                'timezone' => $settings->timezone,
                'workday_start_time' => $settings->workday_start_time,
                'workday_end_time' => $settings->workday_end_time,
                'working_days' => $settings->working_days,
                'repeated_lead_window_days' => $settings->repeated_lead_window_days,
                'rescue_threshold_hours' => $settings->rescue_threshold_hours,
                'first_response_sla_minutes' => $settings->first_response_sla_minutes,
                'follow_up_sla_hours' => $settings->follow_up_sla_hours,
                'stale_conversation_hours' => $settings->stale_conversation_hours,
                'webhook_token_configured' => filled($settings->webhook_token),
            ] : null,
            'pipeline' => $pipeline ? [
                'id' => $pipeline->id,
                'name' => $pipeline->name,
                'is_default' => (bool) $pipeline->is_default,
                'columns' => $columns,
            ] : null,
        ];
    }

    /**
     * @param  array{name?:string,slug?:string,active?:bool}  $payload
     * @return array<string,mixed>
     */
    public function updateCompany(int $companyId, array $payload): array
    {
        $company = Company::query()->findOrFail($companyId);

        $updates = [];
        foreach (['name', 'slug', 'active'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $payload[$field];
            }
        }

        if ($updates !== []) {
            $company->fill($updates)->save();
        }

        return [
            'id' => $company->id,
            'name' => $company->name,
            'slug' => $company->slug,
            'active' => (bool) $company->active,
            'created_at' => optional($company->created_at)?->toISOString(),
            'updated_at' => optional($company->updated_at)?->toISOString(),
        ];
    }
}
