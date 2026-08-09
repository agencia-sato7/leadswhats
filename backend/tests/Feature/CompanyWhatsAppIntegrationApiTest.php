<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyWhatsAppIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CompanyWhatsAppIntegrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_get_returns_initial_state(): void
    {
        $company = $this->createCompany("tenant-a");
        $admin = $this->createUser($company->id, "admin", "admin.whatsapp@test.local");
        $token = $this->login($admin->email);

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->getJson("/api/v1/settings/whatsapp")
            ->assertOk()
            ->assertJsonPath("data.provider", "meta_cloud")
            ->assertJsonPath("data.status", "not_configured")
            ->assertJsonPath("data.access_token_configured", false)
            ->assertJsonPath("data.webhook_verify_token_configured", false)
            ->assertJsonPath("data.phone_number", null)
            ->assertJsonPath("data.phone_number_id", null)
            ->assertJsonPath("data.business_account_id", null)
            ->assertJsonPath("data.connected_at", null)
            ->assertJsonPath("data.last_error", null)
            ->assertJsonMissingPath("data.integration_type")
            ->assertJsonMissingPath("data.session_status")
            ->assertJsonMissingPath("data.qr_code_base64")
            ->assertJsonMissingPath("data.baileys_phone");

        $this->assertFalse(Schema::hasColumn('company_whatsapp_integrations', 'integration_type'));
        $this->assertFalse(Schema::hasColumn('company_whatsapp_integrations', 'session_status'));
        $this->assertFalse(Schema::hasColumn('company_whatsapp_integrations', 'qr_code_base64'));
        $this->assertFalse(Schema::hasColumn('company_whatsapp_integrations', 'baileys_phone'));
    }

    public function test_gestor_get_returns_initial_state(): void
    {
        $company = $this->createCompany("tenant-b");
        $gestor = $this->createUser($company->id, "gestor", "gestor.whatsapp@test.local");
        $token = $this->login($gestor->email);

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->getJson("/api/v1/settings/whatsapp")
            ->assertOk()
            ->assertJsonPath("data.status", "not_configured");
    }

    public function test_unauthenticated_user_gets_401_on_whatsapp_settings_endpoints(): void
    {
        $this->getJson("/api/v1/settings/whatsapp")->assertUnauthorized();

        $this->putJson("/api/v1/settings/whatsapp", $this->validPayload())
            ->assertUnauthorized();
    }

    public function test_sdr_gets_403_on_whatsapp_settings_endpoints(): void
    {
        $company = $this->createCompany("tenant-c");
        $sdr = $this->createUser($company->id, "sdr", "sdr.whatsapp@test.local");
        $token = $this->login($sdr->email);

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->getJson("/api/v1/settings/whatsapp")
            ->assertForbidden();

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->putJson("/api/v1/settings/whatsapp", $this->validPayload())
            ->assertForbidden();
    }

    public function test_admin_put_creates_integration_encrypts_token_and_hides_secrets(): void
    {
        $company = $this->createCompany("tenant-d");
        $admin = $this->createUser($company->id, "admin", "admin.put.whatsapp@test.local");
        $token = $this->login($admin->email);

        $response = $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->putJson("/api/v1/settings/whatsapp", $this->validPayload())
            ->assertOk()
            ->assertJsonPath("data.provider", "meta_cloud")
            ->assertJsonPath("data.status", "configured")
            ->assertJsonPath("data.access_token_configured", true)
            ->assertJsonPath("data.webhook_verify_token_configured", true)
            ->assertJsonPath("data.phone_number_id", "123456")
            ->assertJsonPath("data.business_account_id", "789")
            ->assertJsonMissingPath("data.access_token")
            ->assertJsonPath("data.webhook_verify_token", "verify-token");

        $this->assertDatabaseHas("company_whatsapp_integrations", [
            "company_id" => $company->id,
            "provider" => "meta_cloud",
            "status" => "configured",
            "phone_number" => "+5511999999999",
            "phone_number_id" => "123456",
            "business_account_id" => "789",
        ]);

        $storedCipher = (string) DB::table("company_whatsapp_integrations")
            ->where("company_id", $company->id)
            ->value("access_token_encrypted");

        $this->assertNotSame("token-da-meta", $storedCipher);
        $this->assertNotSame("", $storedCipher);

        $createdAt = $response->json("data.connected_at");
        $this->assertNotNull($createdAt);
    }

    public function test_gestor_put_updates_existing_without_duplicate_rows(): void
    {
        $company = $this->createCompany("tenant-e");
        $gestor = $this->createUser($company->id, "gestor", "gestor.put.whatsapp@test.local");

        CompanyWhatsAppIntegration::create([
            "company_id" => $company->id,
            "provider" => "meta_cloud",
            "status" => "not_configured",
            "phone_number" => null,
            "phone_number_id" => null,
            "business_account_id" => null,
            "access_token_encrypted" => null,
            "webhook_verify_token" => "old-token",
        ]);

        $token = $this->login($gestor->email);

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->putJson("/api/v1/settings/whatsapp", [
                "provider" => "meta_cloud",
                "phone_number" => "+5511888777666",
                "phone_number_id" => "pnid-2",
                "business_account_id" => "ba-2",
                "access_token" => "new-token",
            ])
            ->assertOk()
            ->assertJsonPath("data.status", "configured")
            ->assertJsonPath("data.access_token_configured", true);

        $this->assertSame(1, CompanyWhatsAppIntegration::query()->where("company_id", $company->id)->count());
        $this->assertDatabaseHas("company_whatsapp_integrations", [
            "company_id" => $company->id,
            "phone_number_id" => "pnid-2",
            "business_account_id" => "ba-2",
        ]);
    }

    public function test_provider_invalid_returns_422(): void
    {
        $company = $this->createCompany("tenant-f");
        $admin = $this->createUser($company->id, "admin", "admin.invalid.provider@test.local");
        $token = $this->login($admin->email);

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->putJson("/api/v1/settings/whatsapp", [
                "provider" => "twilio",
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(["provider"]);
    }

    public function test_cross_tenant_access_is_isolated_by_authenticated_company(): void
    {
        $companyA = $this->createCompany("tenant-g-a");
        $companyB = $this->createCompany("tenant-g-b");

        CompanyWhatsAppIntegration::create([
            "company_id" => $companyB->id,
            "provider" => "meta_cloud",
            "status" => "configured",
            "phone_number" => "+5511000000000",
            "phone_number_id" => "pnid-b",
            "business_account_id" => "ba-b",
            "access_token_encrypted" => "secret-b",
            "webhook_verify_token" => "verify-b",
        ]);

        $adminA = $this->createUser($companyA->id, "admin", "admin.cross.tenant@test.local");
        $token = $this->login($adminA->email);

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->getJson("/api/v1/settings/whatsapp")
            ->assertOk()
            ->assertJsonPath("data.status", "not_configured")
            ->assertJsonPath("data.phone_number_id", null)
            ->assertJsonPath("data.access_token_configured", false);
    }

    public function test_status_stays_not_configured_when_minimum_fields_are_missing(): void
    {
        $company = $this->createCompany("tenant-h");
        $admin = $this->createUser($company->id, "admin", "admin.missing.fields@test.local");
        $token = $this->login($admin->email);

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->putJson("/api/v1/settings/whatsapp", [
                "provider" => "meta_cloud",
                "phone_number" => "+5511999990000",
                "phone_number_id" => null,
                "business_account_id" => null,
                "access_token" => "",
            ])
            ->assertOk()
            ->assertJsonPath("data.status", "not_configured")
            ->assertJsonPath("data.access_token_configured", false);
    }

    public function test_get_returns_flags_as_configured_after_valid_save(): void
    {
        $company = $this->createCompany("tenant-i");
        $gestor = $this->createUser($company->id, "gestor", "gestor.flags@test.local");
        $token = $this->login($gestor->email);

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->putJson("/api/v1/settings/whatsapp", $this->validPayload())
            ->assertOk();

        $this->withHeaders(["Authorization" => "Bearer " . $token])
            ->getJson("/api/v1/settings/whatsapp")
            ->assertOk()
            ->assertJsonPath("data.status", "configured")
            ->assertJsonPath("data.access_token_configured", true)
            ->assertJsonPath("data.webhook_verify_token_configured", true)
            ->assertJsonMissingPath("data.access_token")
            ->assertJsonPath("data.webhook_verify_token", "verify-token");
    }

    private function createCompany(string $slug): Company
    {
        return Company::create([
            "name" => "Empresa " . strtoupper(substr($slug, 0, 6)),
            "slug" => $slug,
        ]);
    }

    private function createUser(int $companyId, string $role, string $email): User
    {
        return User::create([
            "company_id" => $companyId,
            "name" => strtoupper($role),
            "email" => $email,
            "password" => Hash::make("12345678"),
            "role" => $role,
            "active" => true,
        ]);
    }

    private function login(string $email): string
    {
        return (string) $this->postJson("/api/v1/auth/login", [
            "email" => $email,
            "password" => "12345678",
        ])->json("token");
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            "provider" => "meta_cloud",
            "phone_number" => "+5511999999999",
            "phone_number_id" => "123456",
            "business_account_id" => "789",
            "access_token" => "token-da-meta",
            "webhook_verify_token" => "verify-token",
        ];
    }
}
