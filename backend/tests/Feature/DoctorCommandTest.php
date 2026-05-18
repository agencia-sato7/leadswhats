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
}

