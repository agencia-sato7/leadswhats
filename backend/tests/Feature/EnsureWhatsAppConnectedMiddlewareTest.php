<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyWhatsAppIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EnsureWhatsAppConnectedMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_is_blocked_when_whatsapp_not_connected(): void
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

        $token = (string) $this->postJson("/api/v1/auth/login", [
            "email" => $user->email,
            "password" => "12345678",
        ])->json("token");

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/dashboard/summary')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'whatsapp_disconnected');
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

        $token = (string) $this->postJson("/api/v1/auth/login", [
            "email" => $user->email,
            "password" => "12345678",
        ])->json("token");

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/dashboard/summary')
            ->assertStatus(200);
    }

    public function test_route_is_blocked_when_status_is_configured_but_meta_credentials_are_missing(): void
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

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/dashboard/summary')
            ->assertStatus(403)
            ->assertJsonPath('error_code', 'whatsapp_disconnected');
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

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/dashboard/summary')
            ->assertOk();
    }
}
