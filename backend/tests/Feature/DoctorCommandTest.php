<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBusinessSetting;
use App\Models\Pipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_reports_current_provider_and_returns_success_when_ready(): void
    {
        config()->set('whatsapp.provider', 'fake');
        config()->set('app.debug', false);

        $company = Company::create([
            'name' => 'Empresa Doctor',
            'slug' => 'empresa-doctor',
        ]);

        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'webhook_token' => 'demo_doctor_token',
        ]);

        Pipeline::create([
            'company_id' => $company->id,
            'name' => 'Pipeline Doctor',
            'is_default' => true,
        ]);

        $this->artisan('leadswhats:doctor')
            ->expectsOutputToContain('[WARN] WHATSAPP_PROVIDER - fake')
            ->expectsOutputToContain('[OK] COMPANIES - total=1')
            ->assertExitCode(0);
    }

    public function test_doctor_fails_when_fake_provider_is_active_in_production_without_explicit_allow(): void
    {
        config()->set('app.env', 'production');
        config()->set('whatsapp.provider', 'fake');
        config()->set('whatsapp.allow_fake_in_production', false);

        $this->artisan('leadswhats:doctor')
            ->expectsOutputToContain('[FAIL] WHATSAPP_PROVIDER_POLICY')
            ->assertExitCode(1);
    }

    public function test_doctor_reports_warning_fail_when_meta_cloud_without_verify_token_or_integrations(): void
    {
        config()->set('whatsapp.provider', 'meta_cloud');
        config()->set('whatsapp.cloud_webhook_verify_token', '');
        config()->set('app.debug', false);

        $this->artisan('leadswhats:doctor')
            ->expectsOutputToContain('[FAIL] WHATSAPP_CLOUD_WEBHOOK_VERIFY_TOKEN')
            ->expectsOutputToContain('[WARN] META_CLOUD_INTEGRATIONS')
            ->assertExitCode(1);
    }

    public function test_doctor_passes_meta_cloud_checks_when_configured(): void
    {
        config()->set('whatsapp.provider', 'meta_cloud');
        config()->set('whatsapp.cloud_webhook_verify_token', 'my-verify-token');
        config()->set('app.debug', false);

        $company = Company::create([
            'name' => 'Meta Company',
            'slug' => 'meta-company',
        ]);

        CompanyBusinessSetting::create([
            'company_id' => $company->id,
            'webhook_token' => 'demo_token',
        ]);

        Pipeline::create([
            'company_id' => $company->id,
            'name' => 'Default Pipeline',
            'is_default' => true,
        ]);

        \App\Models\CompanyWhatsAppIntegration::create([
            'company_id' => $company->id,
            'provider' => 'meta_cloud',
            'status' => 'configured',
            'phone_number' => '+5511999999999',
            'phone_number_id' => '12345',
            'business_account_id' => '67890',
            'access_token_encrypted' => 'my-access-token',
        ]);

        $this->artisan('leadswhats:doctor')
            ->expectsOutputToContain('[OK] WHATSAPP_CLOUD_WEBHOOK_VERIFY_TOKEN')
            ->expectsOutputToContain('[OK] META_CLOUD_INTEGRATIONS - total_configurado=1')
            ->assertExitCode(0);
    }
}

