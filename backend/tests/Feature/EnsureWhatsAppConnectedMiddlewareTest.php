<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyWhatsAppIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnsureWhatsAppConnectedMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_persisted_data_routes_are_free_from_whatsapp_connection_middleware(): void
    {
        $persistedUris = [
            'api/v1/dashboard/summary',
            'api/v1/contacts',
            'api/v1/contacts/export',
            'api/v1/inbox/conversations',
            'api/v1/inbox/conversations/{conversationId}',
            'api/v1/inbox/conversations/{conversationId}/events',
            'api/v1/tasks/checklist',
            'api/v1/users/assignable',
            'api/v1/pipelines',
            'api/v1/pipelines/{pipelineId}/kanban',
            'api/v1/leads/{leadId}/stage-history',
            'api/v1/leads/{leadId}/stage',
            'api/v1/leads/{leadId}/owner',
            'api/v1/pipelines/{pipelineId}/columns',
            'api/v1/kanban-columns/{columnId}',
            'api/v1/leads/sources/unknown',
            'api/v1/leads/sources/recent',
            'api/v1/leads/{leadId}/source',
        ];

        foreach ($persistedUris as $uri) {
            $routes = collect(Route::getRoutes()->getRoutes())->where(fn ($route) => $route->uri() === $uri);
            $this->assertNotEmpty($routes, "Rota persistida não encontrada: {$uri}");

            foreach ($routes as $route) {
                $this->assertNotContains('whatsapp.connected', $route->gatherMiddleware(), "Rota bloqueada indevidamente: {$uri}");
            }
        }
    }

    public function test_persisted_data_route_is_available_when_whatsapp_is_not_connected(): void
    {
        config()->set('whatsapp.test_force_connected_check', true);
        config()->set('whatsapp.provider', 'meta_cloud');

        $company = Company::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Gestor',
            'email' => 'gestor@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => '12345678',
        ])->json('token');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk();
    }

    public function test_route_is_allowed_when_whatsapp_is_connected(): void
    {
        config()->set('whatsapp.test_force_connected_check', true);
        config()->set('whatsapp.provider', 'meta_cloud');

        $company = Company::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
        ]);

        CompanyWhatsAppIntegration::create([
            'company_id' => $company->id,
            'provider' => 'meta_cloud',
            'status' => 'configured',
            'phone_number' => '+5511999999999',
            'phone_number_id' => '12345',
            'business_account_id' => '67890',
            'access_token_encrypted' => 'token',
        ]);

        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Gestor',
            'email' => 'gestor@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);

        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => '12345678',
        ])->json('token');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/dashboard/summary')
            ->assertStatus(200);
    }

    public function test_persisted_data_route_does_not_validate_incomplete_meta_credentials(): void
    {
        config()->set('whatsapp.test_force_connected_check', true);
        config()->set('whatsapp.provider', 'meta_cloud');

        $company = Company::create(['name' => 'Incomplete Meta', 'slug' => 'incomplete-meta']);
        CompanyWhatsAppIntegration::create([
            'company_id' => $company->id,
            'provider' => 'meta_cloud',
            'status' => 'configured',
            'phone_number_id' => '12345',
            'business_account_id' => null,
            'access_token_encrypted' => null,
        ]);
        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Gestor Incomplete',
            'email' => 'gestor.incomplete@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => '12345678',
        ])->json('token');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk();
    }

    public function test_fake_provider_allows_demo_routes_only_outside_production(): void
    {
        config()->set('whatsapp.test_force_connected_check', true);
        config()->set('whatsapp.provider', 'fake');
        config()->set('app.env', 'local');

        $company = Company::create(['name' => 'Demo Fake', 'slug' => 'demo-fake']);
        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Gestor Demo',
            'email' => 'gestor.demo@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => '12345678',
        ])->json('token');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk();
    }

    public function test_local_overview_identifies_demo_mode_without_hiding_integration_status(): void
    {
        config()->set('app.env', 'local');
        config()->set('whatsapp.provider', 'meta_cloud');

        $company = Company::create(['name' => 'Demo Local', 'slug' => 'demo-local']);
        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Gestor Demo',
            'email' => 'gestor.overview.demo@test.local',
            'password' => Hash::make('12345678'),
            'role' => 'gestor',
            'active' => true,
        ]);
        $token = (string) $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => '12345678',
        ])->json('token');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/v1/bootstrap/overview')
            ->assertOk()
            ->assertJsonPath('demo_mode', true)
            ->assertJsonPath('whatsapp_status', 'not_configured');
    }
}
